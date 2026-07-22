const { performance } = require('perf_hooks');

const userId = 1;
const rows = [];
const statusRows = [];

for (let i = 0; i < 5000; i++) {
  rows.push({ id: i, sender_id: i % 2 === 0 ? userId : 2, content: 'test' });
  statusRows.push({ message_id: i, user_id: userId, status: 'read' });
  statusRows.push({ message_id: i, user_id: 2, status: 'delivered' });
}

function decryptServer(c) { return c; }

function original() {
  const start = performance.now();
  const rowsCopy = JSON.parse(JSON.stringify(rows));
  for (const row of rowsCopy) {
    row.content = decryptServer(row.content || '');
    const other = statusRows.find(sr => sr.message_id === row.id && sr.user_id !== userId);
    const my = statusRows.find(sr => sr.message_id === row.id && sr.user_id === userId);
    if (row.sender_id === userId) {
      row.my_status = other ? other.status : 'sent';
    } else {
      row.my_status = my ? my.status : 'sent';
    }
  }
  return performance.now() - start;
}

function optimized() {
  const start = performance.now();
  const rowsCopy = JSON.parse(JSON.stringify(rows));

  const statusMap = new Map();
  for (let i = 0; i < statusRows.length; i++) {
    const sr = statusRows[i];
    let entry = statusMap.get(sr.message_id);
    if (!entry) {
      entry = { my: null, other: null };
      statusMap.set(sr.message_id, entry);
    }
    if (sr.user_id === userId) {
      if (!entry.my) entry.my = sr;
    } else {
      if (!entry.other) entry.other = sr;
    }
  }

  for (const row of rowsCopy) {
    row.content = decryptServer(row.content || '');
    const statuses = statusMap.get(row.id);
    const other = statuses ? statuses.other : null;
    const my = statuses ? statuses.my : null;
    if (row.sender_id === userId) {
      row.my_status = other ? other.status : 'sent';
    } else {
      row.my_status = my ? my.status : 'sent';
    }
  }
  return performance.now() - start;
}

let origTotal = 0;
let optTotal = 0;
for(let i=0; i<10; i++) {
  origTotal += original();
  optTotal += optimized();
}
console.log("Original Avg:", origTotal/10, "ms");
console.log("Optimized Avg:", optTotal/10, "ms");
