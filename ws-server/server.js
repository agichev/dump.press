const { WebSocketServer } = require('ws');
const mysql = require('mysql2/promise');

const WS_PORT = process.env.WS_PORT || 9090;
const DB_CONFIG = {
  host: process.env.DB_HOST || 'localhost',
  port: parseInt(process.env.DB_PORT || '3306'),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'dump_db',
  charset: 'utf8mb4',
  waitForConnections: true,
  connectionLimit: 10,
};

let pool;

const clients = new Map();
const msgTimestamps = new Map();

function getDb() {
  if (!pool) {
    pool = mysql.createPool(DB_CONFIG);
  }
  return pool;
}

async function authenticate(db, token) {
  const [rows] = await db.execute(
    `SELECT u.id, u.username, u.avatar_url
     FROM sessions s JOIN users u ON s.user_id = u.id
     WHERE (s.token = ? OR s.csrf_token = ?) AND s.expires_at > NOW()`,
    [token, token]
  );
  return rows[0] || null;
}

async function sendToUser(userId, payload) {
  const conns = clients.get(String(userId));
  if (!conns || conns.length === 0) return false;
  let delivered = false;
  for (const conn of conns) {
    if (conn.readyState === 1) {
      conn.send(JSON.stringify(payload));
      delivered = true;
    }
  }
  return delivered;
}

async function storeMessage(db, conversationId, senderId, content, replyTo) {
  const [result] = await db.execute(
    'INSERT INTO messages (conversation_id, sender_id, content, reply_to) VALUES (?, ?, ?, ?)',
    [conversationId, senderId, content, replyTo || null]
  );
  const messageId = result.insertId;

  const [participants] = await db.execute(
    'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
    [conversationId, senderId]
  );

  for (const p of participants) {
    await db.execute(
      'INSERT INTO message_status (message_id, user_id, status) VALUES (?, ?, ?)',
      [messageId, p.user_id, 'sent']
    );
  }

  const [msgRows] = await db.execute(
    `SELECT m.*, u.username, u.avatar_url
     FROM messages m JOIN users u ON m.sender_id = u.id
     WHERE m.id = ?`,
    [messageId]
  );

  return msgRows[0] || null;
}

async function markDelivered(db, messageId, userId) {
  await db.execute(
    'UPDATE message_status SET status = ? WHERE message_id = ? AND user_id = ? AND status = ?',
    ['delivered', messageId, userId, 'sent']
  );
}

async function markRead(db, conversationId, userId) {
  await db.execute(
    `UPDATE message_status ms
     JOIN messages m ON ms.message_id = m.id
     SET ms.status = ?, ms.updated_at = NOW()
     WHERE m.conversation_id = ? AND ms.user_id = ? AND ms.status IN ('sent','delivered')`,
    ['read', conversationId, userId]
  );

  await db.execute(
    'UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?',
    [conversationId, userId]
  );
}

async function getConversations(db, userId) {
  const [rows] = await db.execute(
    `SELECT c.id,
            (SELECT m.content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message,
            (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message_at,
            (SELECT m.sender_id FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_sender_id,
            (SELECT COUNT(*) FROM message_status ms JOIN messages m ON ms.message_id = m.id WHERE m.conversation_id = c.id AND ms.user_id = ? AND ms.status IN ('sent','delivered')) as unread_count,
            cp.last_read_at
     FROM conversations c
     JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
     WHERE cp.is_deleted = 0
     ORDER BY c.updated_at DESC`,
    [userId, userId]
  );

  for (const conv of rows) {
    const [participants] = await db.execute(
      `SELECT u.id, u.username, u.avatar_url
       FROM conversation_participants cp JOIN users u ON cp.user_id = u.id
       WHERE cp.conversation_id = ? AND cp.user_id != ?`,
      [conv.id, userId]
    );
    conv.participants = participants;
  }

  return rows;
}

async function getMessages(db, conversationId, userId, before = null, limit = 50) {
  if (!conversationId || !userId) return [];

  let query = `
    SELECT m.id, m.sender_id, m.content, m.reply_to, m.edited_at, m.deleted_at, m.created_at,
           u.username, u.avatar_url
    FROM messages m JOIN users u ON m.sender_id = u.id
    WHERE m.conversation_id = ? AND m.deleted_at IS NULL
  `;
  const params = [conversationId];

  if (before) {
    query += ' AND m.id < ' + parseInt(before);
  }

  query += ' ORDER BY m.id DESC LIMIT ' + parseInt(limit);

  try {
    const [rows] = await db.execute(query, params);
    const msgIds = rows.map(r => r.id);
    if (msgIds.length === 0) return [];

    const placeholders = msgIds.map(() => '?').join(',');
    const [statusRows] = await db.execute(
      `SELECT message_id, user_id, status FROM message_status WHERE message_id IN (${placeholders})`,
      msgIds
    );

    for (const row of rows) {
      const other = statusRows.find(sr => sr.message_id === row.id && sr.user_id !== userId);
      const my = statusRows.find(sr => sr.message_id === row.id && sr.user_id === userId);
      if (row.sender_id === userId) {
        row.my_status = other ? other.status : 'sent';
      } else {
        row.my_status = my ? my.status : 'sent';
      }
    }

    return rows.reverse();
  } catch (e) {
    console.error('getMessages error:', e.message);
    return [];
  }
}

async function getOrCreateConversation(db, userId1, userId2) {
  const [existing] = await db.execute(
    `SELECT c.id FROM conversations c
     JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = ?
     JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = ?
     WHERE (SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = c.id) = 2`,
    [userId1, userId2]
  );

  if (existing.length > 0) {
    const convId = existing[0].id;
    await db.execute(
      'UPDATE conversation_participants SET is_deleted = 0 WHERE conversation_id = ? AND user_id IN (?, ?)',
      [convId, userId1, userId2]
    );
    return convId;
  }

  const [result] = await db.execute('INSERT INTO conversations () VALUES ()');
  const convId = result.insertId;

  await db.execute(
    'INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)',
    [convId, userId1, convId, userId2]
  );

  return convId;
}

async function deleteMessage(db, messageId, userId) {
    await db.execute(
        'DELETE FROM messages WHERE id = ? AND sender_id = ?',
        [messageId, userId]
    );
}

async function editMessage(db, messageId, userId, content) {
  await db.execute(
    'UPDATE messages SET content = ?, edited_at = NOW() WHERE id = ? AND sender_id = ?',
    [content, messageId, userId]
  );
}

async function leaveConversation(db, conversationId, userId) {
  await db.execute(
    'UPDATE conversation_participants SET is_deleted = 1 WHERE conversation_id = ? AND user_id = ?',
    [conversationId, userId]
  );
}

async function clearConversation(db, conversationId, userId) {
  await db.execute(
    'DELETE FROM messages WHERE conversation_id = ?',
    [conversationId]
  );
}

async function blockUser(db, blockerId, blockedId) {
  await db.execute(
    'INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)',
    [blockerId, blockedId]
  );
}

async function isBlocked(db, userId1, userId2) {
  const [rows] = await db.execute(
    'SELECT 1 FROM blocked_users WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1',
    [userId1, userId2, userId2, userId1]
  );
  return rows.length > 0;
}

const wss = new WebSocketServer({ port: WS_PORT });

console.log(`WS server running on port ${WS_PORT}`);

wss.on('connection', (ws, req) => {
  let user = null;
  let pingInterval = null;

  const send = (payload) => {
    if (ws.readyState === 1) {
      ws.send(JSON.stringify(payload));
    }
  };

  ws.on('message', async (raw) => {
    let msg;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      return send({ type: 'error', error: 'Invalid JSON' });
    }

    const db = getDb();

    try {
      if (msg.type === 'auth') {
        user = await authenticate(db, msg.token);
        if (!user) {
          return send({ type: 'error', error: 'Invalid session' });
        }
        const existing = clients.get(String(user.id)) || [];
        if (!existing.includes(ws)) existing.push(ws);
        clients.set(String(user.id), existing);
        send({ type: 'auth_ok', user: { id: user.id, username: user.username, avatar_url: user.avatar_url } });

        const convs = await getConversations(db, user.id);
        send({ type: 'conversations', conversations: convs });

        return;
      }

      if (!user) return send({ type: 'error', error: 'Not authenticated' });

      switch (msg.type) {
        case 'send_message': {
          const convId = msg.conversation_id;

          if (typeof msg.content === 'string' && msg.content.length > 5000) {
            return send({ type: 'error', error: 'Сообщение слишком длинное (максимум 5000 символов)' });
          }

          const [capRows] = await db.execute('SELECT captcha_required FROM users WHERE id = ?', [user.id]);
          if (capRows.length > 0 && capRows[0].captcha_required) {
            return send({ type: 'require_captcha' });
          }

          const now = Date.now();
          if (!msgTimestamps.has(user.id)) msgTimestamps.set(user.id, []);
          const timestamps = msgTimestamps.get(user.id);
          while (timestamps.length > 0 && timestamps[0] < now - 10000) timestamps.shift();
          timestamps.push(now);
          if (timestamps.length > 5) {
            await db.execute('UPDATE users SET captcha_required = 1 WHERE id = ?', [user.id]);
            return send({ type: 'require_captcha' });
          }

          const stored = await storeMessage(db, convId, user.id, msg.content, msg.reply_to);
          if (!stored) return send({ type: 'error', error: 'Failed to store' });

          const [participants] = await db.execute(
            'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
            [convId, user.id]
          );

          stored.my_status = 'sent';
          send({ type: 'new_message', message: stored });

          let anyDelivered = false;
          for (const p of participants) {
            const delivered = await sendToUser(p.user_id, { type: 'new_message', message: stored });
            if (delivered) {
              await markDelivered(db, stored.id, p.user_id);
              anyDelivered = true;
            }
          }
          if (anyDelivered) {
            send({ type: 'status_update', conversation_id: convId, message_id: stored.id, status: 'delivered' });
          }
          break;
        }

        case 'typing': {
          const [participants] = await db.execute(
            'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
            [msg.conversation_id, user.id]
          );
          for (const p of participants) {
            sendToUser(p.user_id, {
              type: 'typing',
              conversation_id: msg.conversation_id,
              user_id: user.id,
              username: user.username,
              is_typing: msg.is_typing,
            });
          }
          break;
        }

        case 'mark_read': {
          await markRead(db, msg.conversation_id, user.id);
          const [participants] = await db.execute(
            'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
            [msg.conversation_id, user.id]
          );
          for (const p of participants) {
            sendToUser(p.user_id, {
              type: 'read_receipt',
              conversation_id: msg.conversation_id,
              user_id: user.id,
            });
          }
          break;
        }

        case 'get_conversations': {
          const convs = await getConversations(db, user.id);
          send({ type: 'conversations', conversations: convs });
          break;
        }

        case 'get_messages': {
          const messages = await getMessages(db, msg.conversation_id, user.id, msg.before, msg.limit || 50);
          send({ type: 'messages', conversation_id: msg.conversation_id, messages, before: msg.before });
          break;
        }

        case 'get_or_create_conv': {
          const [targetUser] = await db.execute(
            'SELECT id, username, avatar_url, privacy_messages FROM users WHERE id = ?',
            [msg.user_id]
          );
          if (!targetUser) return send({ type: 'error', error: 'Пользователь не найден' });

          const blocked = await isBlocked(db, user.id, msg.user_id);
          if (blocked) return send({ type: 'error', error: 'Невозможно начать диалог' });

          if (!targetUser.privacy_messages) {
            const [followCheck] = await db.execute(
              'SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?',
              [user.id, msg.user_id]
            );
            if (!followCheck.length) {
              return send({ type: 'error', error: 'Пользователь не принимает сообщения' });
            }
          }
          const convId = await getOrCreateConversation(db, user.id, msg.user_id);
          const messages = await getMessages(db, convId, user.id);
          send({ type: 'conversation_created', conversation_id: convId, messages, partner: { id: targetUser.id, username: targetUser.username, avatar_url: targetUser.avatar_url } });
          break;
        }

        case 'delete_message': {
          await deleteMessage(db, msg.message_id, user.id);
          send({ type: 'message_deleted', conversation_id: msg.conversation_id, message_id: msg.message_id });
          const [participants] = await db.execute(
            'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
            [msg.conversation_id, user.id]
          );
          for (const p of participants) {
            sendToUser(p.user_id, {
              type: 'message_deleted',
              conversation_id: msg.conversation_id,
              message_id: msg.message_id,
            });
          }
          break;
        }

        case 'edit_message': {
          await editMessage(db, msg.message_id, user.id, msg.content);
          const [participants] = await db.execute(
            'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
            [msg.conversation_id, user.id]
          );
          for (const p of participants) {
            sendToUser(p.user_id, {
              type: 'message_edited',
              conversation_id: msg.conversation_id,
              message_id: msg.message_id,
              content: msg.content,
            });
          }
          break;
        }

        case 'leave_conversation': {
          await leaveConversation(db, msg.conversation_id, user.id);
          send({ type: 'conversation_left', conversation_id: msg.conversation_id });
          break;
        }

        case 'clear_conversation': {
          await clearConversation(db, msg.conversation_id, user.id);
          const [participants] = await db.execute(
            'SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?',
            [msg.conversation_id, user.id]
          );
          for (const p of participants) {
            sendToUser(p.user_id, {
              type: 'conversation_cleared',
              conversation_id: msg.conversation_id,
            });
          }
          send({ type: 'conversation_cleared', conversation_id: msg.conversation_id });
          break;
        }

        case 'block_user': {
          await blockUser(db, user.id, msg.user_id);
          if (msg.conversation_id) {
            await leaveConversation(db, msg.conversation_id, user.id);
            send({ type: 'conversation_left', conversation_id: msg.conversation_id });
          }
          send({ type: 'user_blocked', user_id: msg.user_id });
          break;
        }

        default:
          send({ type: 'error', error: 'Unknown type' });
      }
    } catch (e) {
      console.error('WS error:', e);
      send({ type: 'error', error: 'Server error' });
    }
  });

  ws.on('close', () => {
    if (user) {
      const existing = clients.get(String(user.id)) || [];
      const filtered = existing.filter(c => c !== ws);
      if (filtered.length > 0) clients.set(String(user.id), filtered);
      else clients.delete(String(user.id));
    }
    if (pingInterval) clearInterval(pingInterval);
  });

  ws.on('error', () => {});

  pingInterval = setInterval(() => {
    if (ws.readyState === 1) {
      ws.ping();
    }
  }, 30000);
});
