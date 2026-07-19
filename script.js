        let currentUser = null;
        let csrfToken = '';
        let wsToken = null;
        let wsTokenExpires = 0;
        let wsTokenPromise = null;
        let currentOpenPostId = null;
        let postCommentsCache = {};
        let floatIntervals = {};
        let activeFeedType = 'all';
        let isProcessing = false;
        let pendingPostImageFiles = [];
        let localBookmarksState = {};
        let replyingToCommentId = null;
        let notifPollInterval = null;

        let currentFeedIndex = 0;
        let isScrollingFeed = false;
        let touchStartX = 0;
        let startX = 0;
        let currentX = 0;
        let isDragging = false;
        let wheelTimeout;

        let tfaLoginTempToken = '';
        let tfaLoginMethod = '';

        let tfaSetupTempToken = '';

        let pendingAuth = null;
        let captchaRequired = false;
        let _pendingSpamMessage = null;
        let _pendingWsContent = null;
        let _pendingWsReply = null;
        let _blockedUserIds = new Set();

        async function syncBlockedUsers() {
            try {
                const res = await fetch(apiCall('get_blocked_users'), { headers: { 'X-CSRF': csrfToken } });
                if (!res.ok) return;
                const data = await res.json();
                if (data.success && data.blocked) {
                    _blockedUserIds = new Set(data.blocked.map(Number));
                }
            } catch(e) {}
        }

        function isUserBlocked(userId) {
            return _blockedUserIds.has(Number(userId));
        }

        // Mobile app detection - check for DumpApp in user agent
        const isDumpApp = navigator.userAgent.includes('DumpApp');

        let fcmRegistered = false;
        let fcmPollInterval = null;

        if (isDumpApp) {
            fcmPollInterval = setInterval(() => {
                if (fcmRegistered) return;
                const token = window.__fcmToken;
                if (token && csrfToken) {
                    doRegisterFcmToken(token);
                }
            }, 2000);
        }

        window.fcmRetry = function() {
            if (fcmRegistered) return;
            const token = window.__fcmToken;
            if (token && csrfToken) doRegisterFcmToken(token);
        };

        async function doRegisterFcmToken(token) {
            if (!token || fcmRegistered) return;
            try {
                const res = await fetch(apiCall('register_fcm_token'), {
                    method: 'POST',
                    body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&token=' + encodeURIComponent(token),
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'}
                });
                const data = await res.json();
                if (data.success) {
                    fcmRegistered = true;
                    if (fcmPollInterval) { clearInterval(fcmPollInterval); fcmPollInterval = null; }
                }
            } catch (e) {}
        }

        const GUEST_VIEWED_KEY = 'guest_viewed_posts';
        function getGuestViewed() {
            try { return JSON.parse(localStorage.getItem(GUEST_VIEWED_KEY) || '[]'); } catch(e) { return []; }
        }
        function addGuestViewedPost(id) {
            if (!id) return;
            let arr = getGuestViewed();
            const iid = Number(id);
            if (arr.includes(iid)) return;
            arr.push(iid);
            if (arr.length > 2000) arr = arr.slice(-2000);
            try { localStorage.setItem(GUEST_VIEWED_KEY, JSON.stringify(arr)); } catch(e) {}
        }

        function executeRecaptcha() {
            return new Promise((resolve) => {
                if (!window.RecaptchaSiteKey || typeof grecaptcha === 'undefined') {
                    resolve('');
                    return;
                }
                grecaptcha.ready(() => {
                    grecaptcha.execute(window.RecaptchaSiteKey, { action: 'auth' }).then(resolve);
                });
            });
        }

        const showToast = (msg, isHtml = false) => {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';
            if (isHtml) {
                toast.innerHTML = msg;
            } else {
                toast.textContent = msg;
            }
            container.appendChild(toast);
            let startY = 0, curY = 0, swiping = false;
            const getY = (e) => e.touches ? e.touches[0].clientY : e.clientY;
            const onStart = (e) => { startY = getY(e); curY = startY; swiping = false; };
            const onMove = (e) => {
                if (e.buttons !== undefined && e.buttons !== 1) return;
                curY = getY(e);
                const diff = curY - startY;
                if (Math.abs(diff) > 5) swiping = true;
                if (diff < 0) {
                    toast.style.transform = `translateY(${diff}px)`;
                    toast.style.opacity = Math.max(0, 1 + diff / 100);
                }
            };
            const onEnd = () => {
                const diff = curY - startY;
                if (swiping && diff < -30) {
                    dismissToast();
                }
                toast.style.transform = '';
                toast.style.opacity = '';
            };
            const dismissToast = () => {
                toast.classList.add('fade-out');
                setTimeout(() => { if(toast.parentNode) toast.remove(); }, 300);
            };
            toast.addEventListener('touchstart', onStart, {passive: true});
            toast.addEventListener('touchmove', onMove, {passive: true});
            toast.addEventListener('touchend', onEnd);
            toast.addEventListener('mousedown', onStart);
            toast.addEventListener('mousemove', onMove);
            toast.addEventListener('mouseup', onEnd);
            toast.addEventListener('mouseleave', () => { if (swiping) { toast.style.transform = ''; toast.style.opacity = ''; swiping = false; } });
            setTimeout(dismissToast, 3000);
        };

        const validateFormFields = (form) => {
            const inputs = form.querySelectorAll('input[required], textarea[required]');
            for (let el of inputs) {
                if (!el.value.trim()) {
                    const name = el.getAttribute('data-name') || el.nextElementSibling?.innerText || 'Обязательное поле';
                    showToast(`Пожалуйста, заполните: ${name}`);
                    el.focus();
                    return false;
                }
                if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value)) {
                    showToast('Введите корректный Email');
                    el.focus();
                    return false;
                }
                if (el.minLength && el.value.length < el.minLength) {
                    showToast(`Минимальная длина поля — ${el.minLength} символов`);
                    el.focus();
                    return false;
                }
            }
            return true;
        };

        const requireAuthClient = () => {
            if(!currentUser) {
                showToast("Для этого действия необходимо войти");
                setTimeout(() => navigate('/login'), 1500);
                return false;
            }
            return true;
        };

        let confirmActionCb = null;
        const showConfirm = (title, text, onConfirm) => {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmText').textContent = text;
            confirmActionCb = onConfirm;
            openModal('confirmModal');
        };

        document.getElementById('confirmActionBtn').onclick = () => {
            closeModal('confirmModal');
            if(confirmActionCb) confirmActionCb();
        };

        const linkify = (text) => {
            const hashRegex = /(^|\s)(#[a-zA-Zа-яА-ЯёЁ0-9_]+)/g;
            let safeText = text.replace(hashRegex, '$1<span class="hashtag" onclick="event.stopPropagation(); triggerHashtagSearch(\'$2\')">$2</span>');

            const mentionRegex = /(^|\s)(@(?:[^\s<>"'(){}[\]|\\^,.!?;:\-]+(?:\s[^\s<>"'(){}[\]|\\^,.!?;:\-]+)*))/g;
            safeText = safeText.replace(mentionRegex, '$1<span class="mention" onclick="event.stopPropagation(); triggerMentionProfile(\'$2\')">$2</span>');

            const urlRegex = /(https?:\/\/[^\s<>"']+)/g;
            safeText = safeText.replace(urlRegex, (url) => {
                const h = url.replace(/&quot;/g, '%22').replace(/</g, '%3C').replace(/>/g, '%3E');
                return '<a href="' + h + '" target="_blank" rel="noopener noreferrer" style="color: #ffffff; text-decoration: underline; word-break: break-word;" onclick="event.stopPropagation()">' + url + '</a>';
            });

            return safeText;
        };

        window.triggerHashtagSearch = (tag) => {
            document.getElementById('searchInput').value = tag;
            openModal('searchModal', 'searchInput');
            performSearch();
        };

        window.triggerMentionProfile = async (mention) => {
            const username = mention.startsWith('@') ? mention.slice(1) : mention;
            try {
                const res = await fetch(apiCall('resolve_username') + `&username=${encodeURIComponent(username)}`);
                const data = await res.json();
                if (data.id) {
                    navigate('/profile/' + data.id);
                }
            } catch (e) {}
        };

        const getPlural = (number, one, two, five) => {
            let n = Math.abs(number) % 100, n1 = n % 10;
            if (n > 10 && n < 20) return five;
            if (n1 > 1 && n1 < 5) return two;
            if (n1 === 1) return one;
            return five;
        };

        const parseDateStr = (dateStr) => {
            if (dateStr == null) return null;
            if (typeof dateStr === 'number' || dateStr instanceof Date) return new Date(dateStr);
            const s = String(dateStr).trim();
            if (!s) return null;
            if (s.includes('T')) return new Date(s);
            const d = new Date(s.replace(' ', 'T') + 'Z');
            return isNaN(d) ? new Date(s) : d;
        };

        const timeAgo = (dateStr) => {
            const date = parseDateStr(dateStr);
            if (!date || isNaN(date)) return '';
            const seconds = Math.floor((new Date() - date) / 1000);
            if (seconds < 5) return "Только что";
            if (seconds < 60) return `${seconds} ${getPlural(seconds, 'сек.', 'сек.', 'сек.')} назад`;
            let i = Math.floor(seconds / 31536000); if (i >= 1) return `${i} ${getPlural(i, 'год', 'года', 'лет')} назад`;
            i = Math.floor(seconds / 2592000); if (i >= 1) return `${i} ${getPlural(i, 'мес.', 'мес.', 'мес.')} назад`;
            i = Math.floor(seconds / 86400); if (i >= 1) return `${i} ${getPlural(i, 'день', 'дня', 'дней')} назад`;
            i = Math.floor(seconds / 3600); if (i >= 1) return `${i} ${getPlural(i, 'час', 'часа', 'часов')} назад`;
            i = Math.floor(seconds / 60); return `${i} ${getPlural(i, 'мин.', 'мин.', 'мин.')} назад`;
        };

        const msgTimeShort = (dateStr) => {
            const date = parseDateStr(dateStr);
            if (!date || isNaN(date)) return '';
            const now = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            const sameDay = date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();
            if (sameDay) return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
            const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
            const isYesterday = date.getFullYear() === yesterday.getFullYear() && date.getMonth() === yesterday.getMonth() && date.getDate() === yesterday.getDate();
            if (isYesterday) return 'Вчера';
            if (date.getFullYear() === now.getFullYear()) return `${pad(date.getDate())}.${pad(date.getMonth()+1)}`;
            return `${pad(date.getDate())}.${pad(date.getMonth()+1)}.${date.getFullYear()}`;
        };

        const messageDateKey = (date) => {
            if (!date || isNaN(date)) return '';
            return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
        };

        const messageDateLabel = (date) => {
            if (!date || isNaN(date)) return '';
            return date.toLocaleDateString('ru-RU', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            });
        };

        const maskIp = (ip) => {
            if(!ip || ip === 'Unknown') return 'Скрыт';
            const parts = ip.split('.');
            if(parts.length === 4) return `${parts[0]}.${parts[1]}.***.***`;
            if(ip.includes(':')) return ip.substring(0, 9) + '****:****';
            return '***.***.***.***';
        };

        const formatFileSize = (bytes) => {
            if (bytes < 1024) return bytes + ' Б';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ';
            if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
            return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' ГБ';
        };

        const getFileIcon = (ext) => {
            const map = {
                pdf: '<i class="ph ph-file-pdf"></i>',
                doc: '<i class="ph ph-file-doc"></i>', docx: '<i class="ph ph-file-doc"></i>',
                xls: '<i class="ph ph-file-xls"></i>', xlsx: '<i class="ph ph-file-xls"></i>',
                ppt: '<i class="ph ph-file-ppt"></i>', pptx: '<i class="ph ph-file-ppt"></i>',
                zip: '<i class="ph ph-file-zip"></i>', rar: '<i class="ph ph-file-zip"></i>', '7z': '<i class="ph ph-file-zip"></i>',
                mp3: '<i class="ph ph-file-audio"></i>', wav: '<i class="ph ph-file-audio"></i>', ogg: '<i class="ph ph-file-audio"></i>',
                mp4: '<i class="ph ph-file-video"></i>', mov: '<i class="ph ph-file-video"></i>', avi: '<i class="ph ph-file-video"></i>', mkv: '<i class="ph ph-file-video"></i>',
                txt: '<i class="ph ph-file-text"></i>', csv: '<i class="ph ph-file-text"></i>',
                apk: '<i class="ph ph-android-logo"></i>',
                exe: '<i class="ph ph-windows-logo"></i>',
                jpg: '<i class="ph ph-file-image"></i>', jpeg: '<i class="ph ph-file-image"></i>', png: '<i class="ph ph-file-image"></i>', gif: '<i class="ph ph-file-image"></i>', webp: '<i class="ph ph-file-image"></i>', svg: '<i class="ph ph-file-image"></i>',
                html: '<i class="ph ph-file-code"></i>', css: '<i class="ph ph-file-code"></i>', js: '<i class="ph ph-file-code"></i>', php: '<i class="ph ph-file-code"></i>', json: '<i class="ph ph-file-code"></i>',
            };
            return map[ext] || '<i class="ph ph-file"></i>';
        };

        const resizeTextarea = (el) => { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 120) + 'px'; };

        function chatInputKeydown(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                const form = document.getElementById('chatForm');
                if (form) form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
        window.chatInputKeydown = chatInputKeydown;

        const switchView = (viewId) => {
            ['loginView', 'registerView', 'feedView', 'profileView', 'notificationsView', 'messengerView'].forEach(id => {
                const el = document.getElementById(id);
                if(el) { if (id === viewId) el.classList.add('active'); else el.classList.remove('active'); }
            });
        };

        let _notifCount = 0;

        const updateSeoTitleDynamic = (authorName = null) => {
            let t;
            if (authorName) {
                t = `Публикация от @${authorName} | Dump`;
            } else {
                t = Math.random() > 0.5 ? "Dump" : "Настоящий Dump";
            }
            document.title = _notifCount > 0 ? `(${_notifCount > 9 ? '9+' : _notifCount}) ${t}` : t;
        };

        const navigate = (path, replace = false) => {
            if (!path.startsWith('/')) path = '/' + path;
            const fullPath = BASE_PATH + path;
            if (replace) window.history.replaceState(null, '', fullPath);
            else window.history.pushState(null, '', fullPath);
            handleRoute();
        };

        window.addEventListener('popstate', () => handleRoute());

        function goHome() {
            updateSeoTitleDynamic();
            navigate('/', true);
            loadFeed();
        }

        /* ─── Bottom Navigation ─── */
        window.bottomNavClick = function(nav) {
            if (nav === 'feed') {
                navigate('/');
            } else if (nav === 'search') {
                if (!currentUser) { navigate('/login'); return; }
                openSearch();
            } else if (nav === 'messenger') {
                if (!currentUser) { navigate('/login'); return; }
                navigate('/messages');
            } else if (nav === 'create') {
                if (!currentUser) { navigate('/login'); return; }
                navigate('/create');
            } else if (nav === 'profile') {
                if (!currentUser) { navigate('/login'); return; }
                navigate('/profile');
            }
        };

        function updateBottomNav() {
            const nav = document.getElementById('bottomNav');
            if (!nav) return;
            const path = window.location.pathname;
            let cleanPath = path;
            if (BASE_PATH && cleanPath.startsWith(BASE_PATH)) cleanPath = cleanPath.substring(BASE_PATH.length);
            if (!cleanPath) cleanPath = '/';

            const isGuest = !currentUser;
            const isAuthPage = cleanPath === '/login' || cleanPath === '/register';
            const isLegal = cleanPath.startsWith('/legal/');
            const isPostRoute = cleanPath.startsWith('/post/');
            const isFeed = cleanPath === '/' || isPostRoute;
            const isProfile = cleanPath.startsWith('/profile');
            const isNotifications = cleanPath === '/notifications';
            const isMessenger = cleanPath === '/messages' || cleanPath.startsWith('/messages/');

            if (isAuthPage || isLegal) {
                nav.classList.remove('visible');
            } else if (isGuest && !isPostRoute) {
                nav.classList.remove('visible');
            } else {
                nav.classList.add('visible');
            }

            document.querySelectorAll('.bottom-nav-item').forEach(item => {
                item.classList.remove('active');
            });
            if (isFeed) {
                const el = nav.querySelector('[data-nav="feed"]');
                if (el) el.classList.add('active');
            } else if (isMessenger) {
                const el = nav.querySelector('[data-nav="messenger"]');
                if (el) el.classList.add('active');
            } else if (isProfile || isNotifications) {
                const el = nav.querySelector('[data-nav="profile"]');
                if (el) el.classList.add('active');
            }
        }

        const handleRoute = () => {
            let path = window.location.pathname;
            if (BASE_PATH && path.startsWith(BASE_PATH)) path = path.substring(BASE_PATH.length);
            if (!path) path = '/';

            const nav = document.getElementById('mainNav');
            const feedTabs = document.getElementById('feedTabs');
            
            const isGuest = !currentUser;
            const isPostRoute = path.startsWith('/post/');

            const navBackBtn = document.getElementById('navBackBtn');
            const navLogo = document.getElementById('navLogo');
            if(navBackBtn) navBackBtn.classList.add('hidden');
            if(navLogo) navLogo.classList.remove('hidden');

            if (path.startsWith('/legal/')) {
                const slug = (path.split('/')[2] || '').trim();
                if (slug === 'privacy-policy' || slug === 'rules') {
                    if(nav) nav.classList.remove('visible');
                    updateBottomNav();
                    showLegalModal(slug);
                    return;
                }
            }

            if (isGuest && !isPostRoute && path !== '/' && path !== '/login' && path !== '/register') {
                navigate('/login', true);
                return;
            }

            if (isGuest && path === '/login') { switchView('loginView'); if(nav) nav.classList.remove('visible'); updateBottomNav(); return; }
            if (isGuest && path === '/register') { switchView('registerView'); if(nav) nav.classList.remove('visible'); updateBottomNav(); return; }

            if(nav) nav.classList.add('visible');
            
            if (isGuest) {
                document.getElementById('navUserBtn').onclick = () => navigate('/login');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-sign-in"></i>';
                document.getElementById('navCreateBtn').classList.add('hidden');
                document.getElementById('navNotifBtn').classList.add('hidden');
                document.getElementById('navMsgBtn').classList.add('hidden');
                stopNotifPolling();
            } else {
                document.getElementById('navUserBtn').onclick = () => navigate('/profile');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-user"></i>';
                document.getElementById('navCreateBtn').classList.remove('hidden');
                document.getElementById('navNotifBtn').classList.remove('hidden');
                const beta = isBetaUser();
                document.getElementById('navMsgBtn').classList.toggle('hidden', !beta);
                const bottomMsgBtn = document.querySelector('[data-nav="messenger"]');
                if (bottomMsgBtn) bottomMsgBtn.classList.toggle('hidden', !beta);
                startNotifPolling();
            }
            
            if (path.startsWith('/profile') && !isGuest) {
                switchView('profileView');
                if(feedTabs) feedTabs.classList.add('hidden');
                if(nav) nav.classList.add('show-notif-btn');
                const parts = path.split('/');
                const uid = (parts[parts.length - 1] && parts[parts.length - 1] !== 'profile') ? parseInt(parts[parts.length - 1]) : currentUser.id;
                openProfileData(uid);
                window.scrollTo(0,0);
                updateBottomNav();
            }
            else if ((path === '/messages' || path.startsWith('/messages/')) && !isGuest) {
                if (!isBetaUser()) {
                    navigate('/', true);
                    showToast('Эта функция пока недоступна');
                    return;
                }
                switchView('messengerView');
                if(feedTabs) feedTabs.classList.add('hidden');
                if(nav) nav.classList.add('show-notif-btn');
                const parts = path.split('/');
                const convId = parts[2] ? parseInt(parts[2]) : null;
                openMessenger(convId);
                window.scrollTo(0,0);
                updateBottomNav();
            }
            else if ((path === '/notifications' || path === '/notifications/') && !isGuest) {
                switchView('notificationsView');
                if(feedTabs) feedTabs.classList.add('hidden');
                if(nav) nav.classList.remove('show-notif-btn');
                loadNotifications();
                updateBottomNav();
                if (typeof Android !== 'undefined' && Android.requestNotificationPermission) {
                    Android.requestNotificationPermission();
                }
            }
            else if (path === '/create' && !isGuest) {
                if(feedTabs) feedTabs.classList.add('hidden');
                if(nav) nav.classList.remove('show-notif-btn');
                openModal('createView', 'postContent');
                updateBottomNav();
            } 
            else { 
                switchView('feedView');
                if(feedTabs) feedTabs.classList.remove('hidden');
                if(isGuest && feedTabs) feedTabs.classList.add('hidden'); 
                if(nav) nav.classList.remove('show-notif-btn');
                initTabIndicator(); 
                updateBottomNav();
                
                const createView = document.getElementById('createView');
                if (createView && createView.classList.contains('open')) closeModal('createView');
                
                if (document.getElementById('feedView').innerHTML === '' || isPostRoute) {
                    loadFeed();
                }
            }
        };

        const openModal = (id, focusId = null) => {
            const modal = document.getElementById(id);
            if(!modal) return;
            modal.classList.remove('hidden');
            void modal.offsetWidth;
            modal.classList.add('open');
            if(focusId) setTimeout(() => document.getElementById(focusId).focus(), 300);
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if(!modal) return;
            modal.classList.remove('open');
            setTimeout(() => { modal.classList.add('hidden'); }, 300);
            if(id === 'commentsModal') {
                currentOpenPostId = null;
                cancelReply();
            }
        };

        const closeCreatePost = () => {
            closeModal('createView');
            if(window.history.length > 2) window.history.back();
            else navigate('/', true);
        };

        document.getElementById('createView')?.addEventListener('paste', handlePastePostImages);

        async function openLegal(slug) {
            if (slug !== 'privacy-policy' && slug !== 'rules') return;
            const target = '/legal/' + slug;
            if (window.location.pathname !== BASE_PATH + target) navigate(target);
            showLegalModal(slug);
        }
        async function showLegalModal(slug) {
            openModal('legalModal');
            document.getElementById('legalTitle').textContent = 'Загрузка…';
            document.getElementById('legalBody').innerHTML = '<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size:2.5rem;color:var(--text-muted);"></i></div>';

            // При прямом заходе по URL контент предрендерен сервером.
            const cacheEl = document.getElementById('legalCache-' + slug);
            if (cacheEl && cacheEl.getAttribute('data-html')) {
                renderLegalContent(cacheEl.getAttribute('data-title') || '', cacheEl.getAttribute('data-html'));
                return;
            }
            try {
                const res = await fetch(apiCall('legal') + '&doc=' + encodeURIComponent(slug));
                const data = await res.json();
                if (data.success) renderLegalContent(data.title, data.html);
                else { document.getElementById('legalTitle').textContent = 'Ошибка'; document.getElementById('legalBody').innerHTML = '<p class="text-muted">Не удалось загрузить документ.</p>'; }
            } catch(e) { document.getElementById('legalTitle').textContent = 'Ошибка'; document.getElementById('legalBody').innerHTML = '<p class="text-muted">Ошибка соединения.</p>'; }
        }
        function renderLegalContent(title, html) {
            document.getElementById('legalTitle').textContent = title;
            document.getElementById('legalBody').innerHTML = html;
        }
        function closeLegal() {
            closeModal('legalModal');
            if (window.history.length > 2) window.history.back();
            else navigate('/', true);
        }
        window.openLegal = openLegal;
        window.closeLegal = closeLegal;

        function formatBio(bio) {
            if (!bio) return '';
            const urlRegex = /(https?:\/\/[^\s<>"']+)/g;
            let html = bio;
            html = html.replace(urlRegex, (url) => {
                const safeUrl = url.replace(/&quot;/g, '%22').replace(/</g, '%3C').replace(/>/g, '%3E');
                let host;
                try { host = new URL(url.replace(/&amp;/g, '&')).hostname.replace(/^www\./, ''); } catch(e) { host = url.replace(/&quot;/g, '').replace(/</g, '').replace(/>/g, ''); }
                const fav = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(host) + '&sz=64';
                return '<a class="bio-link-pill" href="' + safeUrl + '" target="_blank" rel="noopener noreferrer nofollow" onclick="event.stopPropagation()">' +
                    '<img class="bio-link-favicon" src="' + fav + '" alt="" onerror="this.style.visibility=\'hidden\'">' +
                    '<span class="bio-link-text">' + host.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span></a>';
            });
            html = html.replace(/\n/g, '<br>');
            return html;
        }

        const closeModalOnOutsideClick = (e, modalId, routeBack = false, isCreate = false) => {
            if (e.target.id === modalId) { 
                if (isCreate) closeCreatePost();
                else if (routeBack) navigate('/'); 
                else closeModal(modalId); 
            }
        };

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const legal = document.getElementById('legalModal');
                if (legal && legal.classList.contains('open')) { closeLegal(); return; }
                ['postOptionsModal', 'commentsModal', 'settingsModal', 'cropModal', 'searchModal', 'passwordModal', 'confirmModal', 'textWarningModal', 'tfaSettingsModal', 'tfaLoginModal', 'turnstileModal', 'followingModal', 'newChatModal', 'emojiPicker'].filter(id => id !== 'spamCaptchaModal').forEach(id => {
                    const m = document.getElementById(id);
                    if(m && m.classList.contains('open')) closeModal(id);
                });
                const create = document.getElementById('createView');
                if(create && create.classList.contains('open')) closeCreatePost();
            }
            
            if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
                const createView = document.getElementById('createView');
                if (createView && createView.classList.contains('open')) {
                    handlePastePostImages(e);
                }
            }

            if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                const target = e.target;
                if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)) return;
                const wrapper = document.getElementById('feedWrapper');
                if (!wrapper || !wrapper.children.length) return;
                const openModal = document.querySelector('.modal-overlay.open, #createView.open');
                if (openModal) return;
                e.preventDefault();
                goToFeedPost(currentFeedIndex + (e.key === 'ArrowRight' ? 1 : -1));
            }
        });

        async function handleAuth(e, action) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if (isProcessing) return;
            await __waitForIp();

            const token = RECAPTCHA_ENABLED && !isDumpApp ? await executeRecaptcha() : '';
            await doAuth(action, form, token);
        }

        async function doAuth(action, form, recaptchaToken) {
            if (isProcessing) return;
            isProcessing = true;

            const fd = new FormData(form);
            fd.append('csrf_token', csrfToken || '');
            if (recaptchaToken) fd.append('recaptcha_token', recaptchaToken);
            if (window.__clientIp) fd.append('client_ip', window.__clientIp);

            const btn = setFormState(form, true);
            const origText = btn.textContent;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';

            try {
                const res = await fetch(apiCall(action), { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    if (data.require_2fa) {
                        tfaLoginTempToken = data.temp_token;
                        tfaLoginMethod = data.method;

                        const icon = document.getElementById('tfaLoginIcon');
                        const desc = document.getElementById('tfaLoginDesc');
                        if (tfaLoginMethod === 'email') {
                            icon.className = 'ph ph-envelope-simple mb-4';
                            desc.textContent = 'Мы отправили код подтверждения на ваш Email.';
                        } else {
                            icon.className = 'ph ph-device-mobile mb-4';
                            desc.textContent = 'Введите код из приложения (Authenticator).';
                        }

                        document.getElementById('tfaLoginCode').value = '';
                        openModal('tfaLoginModal', 'tfaLoginCode');
                    } else if (data.require_email_verification) {
                        const userCode = prompt('Мы отправили код подтверждения на ваш email. Введите 6 цифр:');
                        if (userCode) {
                            const vfd = new FormData();
                            vfd.append('code', userCode);
                            vfd.append('temp_token', data.temp_token);
                            vfd.append('csrf_token', csrfToken || '');
                            try {
                                const vres = await fetch(apiCall('verify_email'), { method: 'POST', body: vfd });
                                const vdata = await vres.json();
                                if (vdata.success) showToast('Email подтверждён');
                                else showToast(vdata.error || 'Неверный код');
                            } catch (e) { showToast('Ошибка подтверждения email'); }
                        }
                        form.reset();
                        await init();
                        navigate('/', true);
                    } else {
                        form.reset();
                        await init();
                        navigate('/', true);
                    }
                } else if (data.require_turnstile) {
                    btn.textContent = origText;
                    setFormState(form, false);
                    isProcessing = false;
                    showTurnstileModal(action, form);
                    return;
                } else showToast(data.error || 'Ошибка');
            } catch (err) { showToast('Ошибка соединения'); }
            finally { btn.textContent = origText; setFormState(form, false); isProcessing = false; }
        }

        let pendingTurnstileAuth = null;

        function showTurnstileModal(action, form) {
            pendingTurnstileAuth = { action, form };
            openModal('turnstileModal', 'turnstileWidget');

            const widget = document.getElementById('turnstileWidget');
            if (!widget) return;
            widget.innerHTML = '';

            tryRenderTurnstile(widget, 0);
        }

        function tryRenderTurnstile(widget, attempt) {
            if (!TURNSTILE_SITE_KEY) return;

            if (window.turnstile) {
                try {
                    turnstile.render(widget, {
                        sitekey: TURNSTILE_SITE_KEY,
                        callback: function(token) {
                            onTurnstilePassed(token);
                        }
                    });
                    return;
                } catch (e) {
                    console.warn('Turnstile render failed:', e);
                }
            }

            if (attempt < 20) {
                setTimeout(() => tryRenderTurnstile(widget, attempt + 1), 1000);
            }
        }

        async function onTurnstilePassed(token) {
            if (!pendingTurnstileAuth) return;
            const { action, form } = pendingTurnstileAuth;
            pendingTurnstileAuth = null;

            const fd = new FormData(form);
            fd.append('csrf_token', csrfToken || '');
            fd.append('turnstile_token', token);
            if (window.__clientIp) fd.append('client_ip', window.__clientIp);

            const btn = form.querySelector('button[type="submit"]');
            const origText = btn ? btn.textContent : '';
            if (btn) btn.innerHTML = '<i class="ph ph-spinner spin"></i>';

            try {
                const res = await fetch(apiCall(action), { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    closeModal('turnstileModal');
                    if (data.require_2fa) {
                        tfaLoginTempToken = data.temp_token;
                        tfaLoginMethod = data.method;
                        const icon = document.getElementById('tfaLoginIcon');
                        const desc = document.getElementById('tfaLoginDesc');
                        if (tfaLoginMethod === 'email') {
                            icon.className = 'ph ph-envelope-simple mb-4';
                            desc.textContent = 'Мы отправили код подтверждения на ваш Email.';
                        } else {
                            icon.className = 'ph ph-device-mobile mb-4';
                            desc.textContent = 'Введите код из приложения (Authenticator).';
                        }
                        document.getElementById('tfaLoginCode').value = '';
                        openModal('tfaLoginModal', 'tfaLoginCode');
                    } else if (data.require_email_verification) {
                        const userCode = prompt('Мы отправили код подтверждения на ваш email. Введите 6 цифр:');
                        if (userCode) {
                            const vfd = new FormData();
                            vfd.append('code', userCode);
                            vfd.append('temp_token', data.temp_token);
                            vfd.append('csrf_token', csrfToken || '');
                            try {
                                const vres = await fetch(apiCall('verify_email'), { method: 'POST', body: vfd });
                                const vdata = await vres.json();
                                if (vdata.success) showToast('Email подтверждён');
                                else showToast(vdata.error || 'Неверный код');
                            } catch (e) { showToast('Ошибка подтверждения email'); }
                        }
                        form.reset();
                        await init();
                        navigate('/', true);
                    } else {
                        form.reset();
                        await init();
                        navigate('/', true);
                    }
                } else {
                    showToast(data.error || 'Ошибка');
                }
            } catch (err) {
                showToast('Ошибка соединения');
            } finally {
                if (btn) btn.textContent = origText;
            }
        }

        function showSpamCaptcha() {
            openModal('spamCaptchaModal', 'spamTurnstileWidget');
            const widget = document.getElementById('spamTurnstileWidget');
            if (!widget) return;
            widget.innerHTML = '';
            tryRenderSpamTurnstile(widget, 0);
        }

        function tryRenderSpamTurnstile(widget, attempt) {
            if (!TURNSTILE_SITE_KEY) return;
            if (window.turnstile) {
                try {
                    turnstile.render(widget, {
                        sitekey: TURNSTILE_SITE_KEY,
                        callback: function(token) {
                            onSpamCaptchaPassed(token);
                        }
                    });
                    return;
                } catch (e) {
                    console.warn('Spam Turnstile render failed:', e);
                }
            }
            if (attempt < 20) {
                setTimeout(() => tryRenderSpamTurnstile(widget, attempt + 1), 1000);
            }
        }

        async function onSpamCaptchaPassed(token) {
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken || '');
                fd.append('turnstile_token', token);
                const res = await fetch(apiCall('unlock_captcha'), { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    captchaRequired = false;
                    if (currentUser) currentUser.captcha_required = false;
                    closeModal('spamCaptchaModal');
                    if (_pendingSpamMessage) {
                        const fn = _pendingSpamMessage;
                        _pendingSpamMessage = null;
                        fn();
                    }
                } else {
                    showToast(data.error || 'Ошибка проверки');
                    setTimeout(() => showSpamCaptcha(), 500);
                }
            } catch (e) {
                showToast('Ошибка соединения');
                setTimeout(() => showSpamCaptcha(), 500);
            }
        }

        async function verifyTfaLogin(e) {
            e.preventDefault();
            const code = document.getElementById('tfaLoginCode').value.trim();
            if (code.length !== 6) { showToast('Введите 6 цифр'); return; }
            
            const btn = document.getElementById('tfaLoginBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            btn.disabled = true;

            try {
                const fd = new FormData();
                fd.append('temp_token', tfaLoginTempToken);
                fd.append('code', code);
                if (window.__clientIp) fd.append('client_ip', window.__clientIp);
                
                const res = await fetch(apiCall('tfa_verify_login'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    closeModal('tfaLoginModal');
                    await init();
                    navigate('/', true);
                } else {
                    showToast(data.error || 'Неверный код');
                }
            } catch (err) {
                showToast('Ошибка при проверке кода');
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        function cancelTfaLogin() {
            closeModal('tfaLoginModal');
            tfaLoginTempToken = '';
        }

        async function openTfaSettingsModal() {
            try {
                const res = await fetch(apiCall('tfa_settings'));
                const data = await res.json();
                
                const toggle = document.getElementById('tfaToggle');
                const setupContainer = document.getElementById('tfaSetupContainer');
                const verifyContainer = document.getElementById('tfaVerifyContainer');
                
                toggle.checked = data.data.tfa_enabled == 1;
                setupContainer.classList.add('hidden');
                verifyContainer.classList.add('hidden');
                
                updateTfaBadgeStatus(toggle.checked);
                openModal('tfaSettingsModal');
            } catch (e) {
                showToast("Ошибка загрузки настроек 2FA");
            }
        }

        function updateTfaBadgeStatus(isEnabled) {
            const badge = document.getElementById('tfaStatusBadge');
            if (isEnabled) {
                badge.textContent = 'Вкл';
                badge.style.background = 'rgba(52, 211, 153, 0.2)';
                badge.style.color = '#34d399';
            } else {
                badge.textContent = 'Выкл';
                badge.style.background = 'var(--surface-hover)';
                badge.style.color = 'var(--text-muted)';
            }
        }

        function handleTfaToggleChange() {
            const toggle = document.getElementById('tfaToggle');
            const setupContainer = document.getElementById('tfaSetupContainer');
            const verifyContainer = document.getElementById('tfaVerifyContainer');
            
            verifyContainer.classList.add('hidden');
            
            if (toggle.checked) {
                setupContainer.classList.remove('hidden');
                changeTfaMethodPreview();
            } else {
                setupContainer.classList.add('hidden');
                showConfirm('Отключение 2FA', 'Вы уверены, что хотите отключить двухфакторную аутентификацию? Ваша учетная запись станет менее защищенной.', () => {
                    disableTfa();
                });
                toggle.checked = true;
            }
        }

        function changeTfaMethodPreview() {
            const method = document.querySelector('input[name="tfaMethod"]:checked').value;
            if (method === 'email') {
                document.getElementById('tfaPreviewEmail').classList.remove('hidden');
                document.getElementById('tfaPreviewApp').classList.add('hidden');
            } else {
                document.getElementById('tfaPreviewEmail').classList.add('hidden');
                document.getElementById('tfaPreviewApp').classList.remove('hidden');
            }
        }

        async function startTfaSetup() {
            const method = document.querySelector('input[name="tfaMethod"]:checked').value;
            const btn = document.getElementById('tfaStartSetupBtn');
            const orig = btn.innerHTML;
            
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            btn.disabled = true;
            
            try {
                const fd = new FormData();
                fd.append('method', method);
                fd.append('csrf_token', csrfToken);
                
                const res = await fetch(apiCall('tfa_setup_start'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    tfaSetupTempToken = data.temp_token;
                    document.getElementById('tfaSetupContainer').classList.add('hidden');
                    document.getElementById('tfaVerifyContainer').classList.remove('hidden');
                    document.getElementById('tfaSetupCode').value = '';
                    
                    if (method === 'app') {
                        document.getElementById('tfaAppQrContainer').classList.remove('hidden');
                        document.getElementById('tfaEmailSentMessage').classList.add('hidden');
                        document.getElementById('tfaQrImage').src = data.qr_url;
                        document.getElementById('tfaSecretKey').textContent = data.secret;
                    } else {
                        document.getElementById('tfaAppQrContainer').classList.add('hidden');
                        document.getElementById('tfaEmailSentMessage').classList.remove('hidden');
                    }
                    
                    setTimeout(() => document.getElementById('tfaSetupCode').focus(), 100);
                } else {
                    showToast(data.error || 'Ошибка');
                }
            } catch (e) {
                showToast('Ошибка соединения');
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        async function confirmTfaSetup() {
            const code = document.getElementById('tfaSetupCode').value.trim();
            if (code.length !== 6) { showToast('Введите 6 цифр'); return; }
            
            const btn = document.getElementById('tfaConfirmSetupBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            btn.disabled = true;
            
            try {
                const fd = new FormData();
                fd.append('temp_token', tfaSetupTempToken);
                fd.append('code', code);
                fd.append('csrf_token', csrfToken);
                
                const res = await fetch(apiCall('tfa_setup_verify'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Двухфакторная аутентификация включена!');
                    closeModal('tfaSettingsModal');
                    updateTfaBadgeStatus(true);
                    currentUser.tfa_enabled = 1;
                } else {
                    showToast(data.error || 'Неверный код. Попробуйте еще раз.');
                }
            } catch (e) {
                showToast('Ошибка при подтверждении');
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        async function disableTfa(phase = 'start', tempToken = '', code = '') {
            try {
                const password = phase === 'start' ? prompt('Введите текущий пароль для подтверждения') : '';
                if (phase === 'start' && password === null) return;

                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('current_password', phase === 'start' ? (password || '') : '');
                if (phase === 'verify') {
                    fd.append('temp_token', tempToken);
                    fd.append('code', code);
                }
                const res = await fetch(apiCall('tfa_disable'), { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    showToast('Двухфакторная аутентификация отключена');
                    document.getElementById('tfaToggle').checked = false;
                    document.getElementById('tfaSetupContainer').classList.add('hidden');
                    updateTfaBadgeStatus(false);
                    currentUser.tfa_enabled = 0;
                } else if (data.require_code) {
                    const userCode = prompt('Введите код из email для отключения 2FA');
                    if (userCode) await disableTfa('verify', data.temp_token, userCode);
                } else {
                    showToast(data.error || 'Ошибка отключения 2FA');
                }
            } catch(e) {
                showToast("Ошибка отключения 2FA");
            }
        }

        function markPostAsSeen(postId) {
            if (!postId) return;
            if (currentUser) {
                const fd = new FormData();
                fd.append('post_id', postId);
                fd.append('csrf_token', csrfToken);
                fetch(apiCall('mark_seen'), { method: 'POST', body: fd, keepalive: true }).catch(()=>{});
            } else {
                addGuestViewedPost(postId);
            }
        }

        const feedViewEl = document.getElementById('feedView');
        
        feedViewEl.addEventListener('wheel', (e) => {
            if (!feedViewEl.classList.contains('active') || isScrollingFeed) return;
            
            const scrollable = e.target.closest('.scrollable-overlay') || e.target.closest('.post-text-content');
            if (scrollable) {
                const atTop = scrollable.scrollTop <= 0;
                const atBottom = scrollable.scrollTop + scrollable.clientHeight >= scrollable.scrollHeight - 1;
                if (e.deltaY > 0 && !atBottom) return;
                if (e.deltaY < 0 && !atTop) return;
            }

            if (Math.abs(e.deltaX) > 20 || Math.abs(e.deltaY) > 20) {
                const isNext = (Math.abs(e.deltaX) > Math.abs(e.deltaY)) ? e.deltaX > 0 : e.deltaY > 0;
                isScrollingFeed = true;
                if (isNext) goToFeedPost(currentFeedIndex + 1);
                else goToFeedPost(currentFeedIndex - 1);

                clearTimeout(wheelTimeout);
                wheelTimeout = setTimeout(() => { isScrollingFeed = false; }, 500);
            }
        }, {passive: true});

        

        let touchStartY = 0;
        let swipeDirection = null; // 'horizontal', 'vertical', null

        function handleSwipeStart(e) {
            if (!feedViewEl.classList.contains('active') || isScrollingFeed) return;
            
            if (e.target.closest('button') || e.target.closest('.action-btn') || e.target.closest('.scrollable-overlay') || e.target.closest('.post-author') || e.target.closest('a') || e.target.closest('.hashtag') || e.target.closest('.comment-input-wrapper')) return;
            
            isDragging = true;
            swipeDirection = null;
            startX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
            touchStartY = e.type.includes('mouse') ? e.pageY : e.touches[0].clientY;
            currentX = startX;
            
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transition = 'none';
        }

        function handleSwipeMove(e) {
            if (!isDragging) return;
            const clientX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
            const clientY = e.type.includes('mouse') ? e.pageY : e.touches[0].clientY;
            const diffX = clientX - startX;
            const diffY = clientY - touchStartY;

            if (!swipeDirection) {
                if (Math.abs(diffX) < 10 && Math.abs(diffY) < 10) return;
                swipeDirection = Math.abs(diffX) > Math.abs(diffY) ? 'horizontal' : 'vertical';
            }

            if (swipeDirection === 'vertical') {
                isDragging = false;
                return;
            }

            e.preventDefault();
            currentX = clientX;
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transform = `translateX(calc(-${currentFeedIndex * 100}vw + ${diffX}px))`;
        }

        function handleSwipeEnd(e) {
            if (!isDragging) return;
            isDragging = false;
            
            const diff = currentX - startX;
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transition = 'transform 0.4s cubic-bezier(0.25, 1, 0.5, 1)';
            
            if (Math.abs(diff) > window.innerWidth / 5 || Math.abs(diff) > 100) {
                if (diff < 0) goToFeedPost(currentFeedIndex + 1);
                else goToFeedPost(currentFeedIndex - 1);
            } else {
                goToFeedPost(currentFeedIndex);
            }
            currentX = startX;
            swipeDirection = null;
        }

        feedViewEl.addEventListener('touchstart', handleSwipeStart, {passive: true});
        feedViewEl.addEventListener('touchmove', handleSwipeMove, {passive: false});
        feedViewEl.addEventListener('touchend', handleSwipeEnd);
        
        feedViewEl.addEventListener('mousedown', handleSwipeStart);
        feedViewEl.addEventListener('mousemove', handleSwipeMove);
        feedViewEl.addEventListener('mouseup', handleSwipeEnd);
        feedViewEl.addEventListener('mouseleave', handleSwipeEnd);

        function initPostCarousel(index) {
            const wrapper = document.getElementById('feedWrapper');
            if (!wrapper) return;
            
            if (window.postSliderInterval) { clearInterval(window.postSliderInterval); window.postSliderInterval = null; }
            if (window.postSliderRaf) { cancelAnimationFrame(window.postSliderRaf); window.postSliderRaf = null; }
            const activeSlider = wrapper.children[index]?.querySelector('.image-slider');
            if (activeSlider && activeSlider.children.length > 1) {
                const imagesCount = activeSlider.children.length;
                const dotsContainer = activeSlider.nextElementSibling;
                const dots = dotsContainer?.querySelectorAll('.slider-dot');
                const SLIDE_INTERVAL = 2000;
                
                if(dots) {
                    dots.forEach(d => d.classList.remove('active'));
                    if(dots[0]) dots[0].classList.add('active');
                }
                let currentSlideIndex = 0;
                let slideTimerStart = performance.now();
                let rafId = null;

                function advanceSlide() {
                    currentSlideIndex = (currentSlideIndex + 1) % imagesCount;
                    activeSlider.style.transform = `translateX(-${currentSlideIndex * 100}%) translateZ(0)`;
                    
                    if(dots) {
                        dots.forEach((d, i) => {
                            d.classList.remove('active');
                            if (i === currentSlideIndex) { void d.offsetWidth; d.classList.add('active'); }
                        });
                    }
                }

                function scheduleNext() {
                    slideTimerStart = performance.now();
                    rafId = requestAnimationFrame(function tick(now) {
                        if (now - slideTimerStart >= SLIDE_INTERVAL) {
                            advanceSlide();
                            scheduleNext();
                            return;
                        }
                        window.postSliderRaf = requestAnimationFrame(tick);
                    });
                    window.postSliderRaf = rafId;
                }

                scheduleNext();
            }
        }

        function goToFeedPost(index) {
            const wrapper = document.getElementById('feedWrapper');
            if (!wrapper) return;
            
            const maxIndex = wrapper.children.length - 1;
            if (index < 0) index = 0;
            if (index > maxIndex) index = maxIndex;
            
            isScrollingFeed = true;
            
            const oldPostId = wrapper.children[currentFeedIndex]?.dataset?.id;
            if (oldPostId) stopFloatingComments(oldPostId);

            currentFeedIndex = index;
            wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;
            
            const newPostId = wrapper.children[currentFeedIndex]?.dataset?.id;
            const newPostSlug = wrapper.children[currentFeedIndex]?.dataset?.slug;
            
            setTimeout(() => {
                isScrollingFeed = false;
                if (newPostId) {
                    startFloatingComments(newPostId);
                    markPostAsSeen(newPostId);
                }
                
                initPostCarousel(currentFeedIndex);
                preloadFeedImages(currentFeedIndex);
                
                const postAuthor = wrapper.children[currentFeedIndex]?.querySelector('.author-name')?.textContent;
                updateSeoTitleDynamic(postAuthor);

                if (newPostSlug && window.location.pathname !== BASE_PATH + `/post/${newPostSlug}`) {
                    window.history.replaceState(history.state, '', BASE_PATH + `/post/${newPostSlug}`);
                }
            }, 420);
        }

        function preloadFeedImages(index) {
            const wrapper = document.getElementById('feedWrapper');
            if (!wrapper) return;
            const total = wrapper.children.length;
            for (let i = index + 1; i < total - 1; i++) {
                const card = wrapper.children[i];
                if (!card) continue;
                const imgs = card.querySelectorAll('img[src]');
                imgs.forEach(img => {
                    const src = img.getAttribute('src');
                    if (src && !src.startsWith('data:')) {
                        const p = new Image();
                        p.src = src;
                    }
                });
            }
        }

        async function init() {
            try {
                const res = await fetch(apiCall('me'), { cache: 'no-store' });
                if(!res.ok) throw new Error('API Error');
                const data = await res.json();
                csrfToken = data.csrf || '';
                currentUser = data.user || null;
                captchaRequired = currentUser && currentUser.captcha_required ? true : false;
            } catch (e) { showToast('Не удалось связаться с сервером'); } 
            finally {
                handleRoute();
                if (currentUser) syncBlockedUsers();
                if (captchaRequired) showSpamCaptcha();
                updateBannerVisibility();
            }
        }

        const setFormState = (form, disabled) => {
            const btn = form.querySelector('button[type="submit"]');
            form.querySelectorAll('input, textarea, button').forEach(i => i.disabled = disabled);
            return btn;
        };

        async function logout() {
            const fd = new FormData(); fd.append('csrf_token', csrfToken);
            await fetch(apiCall('logout'), { method: 'POST', body: fd });
            currentUser = null; csrfToken = ''; postCommentsCache = {};
            stopNotifPolling();
            stopConvPolling();
            if (ws) { ws.close(); ws = null; }
            wsConnected = false;
            messengerInitialized = false;
            const keysToRemove = [];
            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                if (k && k.startsWith('dr_')) keysToRemove.push(k);
            }
            keysToRemove.forEach(k => localStorage.removeItem(k));
            document.getElementById('feedView').innerHTML = ''; 
            closeModal('settingsModal');
            navigate('/login', true);
        }

        function compressImage(file) {
            return new Promise((resolve) => {
                if (!file.type.startsWith('image/') || file.type === 'image/gif' || file.type === 'image/svg+xml' || file.type === 'image/webp') {
                    resolve(file); return;
                }
                const img = new Image();
                const url = URL.createObjectURL(file);
                img.onload = () => {
                    URL.revokeObjectURL(url);
                    const MAX_DIM = 1920;
                    const TARGET_SIZE = 150 * 1024;
                    let w = img.width, h = img.height;
                    if (w > MAX_DIM || h > MAX_DIM) {
                        if (w > h) { h = Math.round(h * MAX_DIM / w); w = MAX_DIM; }
                        else { w = Math.round(w * MAX_DIM / h); h = MAX_DIM; }
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(img, 0, 0, w, h);

                    function tryQuality(lo, hi, bestBlob) {
                        if (hi - lo < 0.02) {
                            const ext = file.name.split('.').slice(0, -1).join('.') || 'image';
                            const result = bestBlob.size > 0 && bestBlob.size < file.size ? new File([bestBlob], `${ext}.jpg`, { type: 'image/jpeg' }) : file;
                            resolve(result); return;
                        }
                        const mid = (lo + hi) / 2;
                        canvas.toBlob((blob) => {
                            if (!blob) { resolve(file); return; }
                            if (blob.size <= TARGET_SIZE) {
                                tryQuality(mid, hi, blob);
                            } else {
                                tryQuality(lo, mid, bestBlob.size === 0 || blob.size < bestBlob.size ? blob : bestBlob);
                            }
                        }, 'image/jpeg', mid);
                    }
                    tryQuality(0.5, 0.95, new Blob());
                };
                img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
                img.src = url;
            });
        }

        async function uploadToImgBB(file) {
            file = await compressImage(file);
            const fd = new FormData(); 
            fd.append('image', file);
            fd.append('csrf_token', csrfToken);
            try {
                const res = await fetch(apiCall('upload_image'), { method: 'POST', body: fd });
                const data = await res.json();
                return data.success ? data.url : null;
            } catch { 
                showToast('Ошибка загрузки медиа'); 
                return null; 
            }
        }

        function openSettings() {
            document.getElementById('settingsAvatarPreview').src = getProxyUrl(currentUser.avatar_url || `https://ui-avatars.com/api/?name=${currentUser.username}&background=random`);
            document.getElementById('settingsBio').value = currentUser.bio || '';
            document.getElementById('settingsBookmarksPublic').checked = currentUser.bookmarks_public != 0;
            document.getElementById('accUsername').value = currentUser.username || '';
            document.getElementById('accEmail').value = currentUser.email || '';
            switchSettingsTab('profile');
            openModal('settingsModal');
        }

        function switchSettingsTab(tab) {
            ['profile', 'account', 'sessions'].forEach(t => {
                document.getElementById('pane' + t.charAt(0).toUpperCase() + t.slice(1)).classList.add('hidden');
                document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1)).classList.remove('active');
            });
            document.getElementById('pane' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.remove('hidden');
            document.getElementById('tabBtn' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
            
            if(tab === 'sessions') loadSessions();
        }

        function openPasswordModal() {
            document.getElementById('chPassCurrent').value = '';
            document.getElementById('chPassNew').value = '';
            document.getElementById('chPassConfirm').value = '';
            openModal('passwordModal', 'chPassCurrent');
        }

        let cropper = null;
        let pendingSettingsAvatarBlob = null;

        function loadCropperJs(callback) {
            if (typeof Cropper !== 'undefined') { callback(); return; }
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css';
            document.head.appendChild(link);
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js';
            script.onload = callback;
            document.head.appendChild(script);
        }

        function initCrop(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой (макс 20 МБ)'); return; }
            
            const reader = new FileReader();
            reader.onload = (event) => {
                document.getElementById('cropImage').src = event.target.result;
                openModal('cropModal');
                loadCropperJs(() => {
                    if (cropper) cropper.destroy();
                    cropper = new Cropper(document.getElementById('cropImage'), {
                        aspectRatio: 1, viewMode: 1, background: false, autoCropArea: 0.8, responsive: true
                    });
                });
            };
            reader.readAsDataURL(file);
            e.target.value = ''; 
        }

        function cancelCrop() { closeModal('cropModal'); if (cropper) { cropper.destroy(); cropper = null; } }

        function doCrop() {
            if (!cropper) return;
            const btn = document.getElementById('cropBtn');
            btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            
            cropper.getCroppedCanvas({ width: 400, height: 400 }).toBlob(async (blob) => {
                pendingSettingsAvatarBlob = blob;
                document.getElementById('settingsAvatarPreview').src = URL.createObjectURL(blob);
                closeModal('cropModal');
                cropper.destroy(); cropper = null;
                btn.disabled = false; btn.innerHTML = 'Применить';
            }, 'image/jpeg', 0.9);
        }

        async function saveProfile(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = 'Сохранение...';

            try {
                let avatarUrl = '';
                if (pendingSettingsAvatarBlob) {
                    const file = new File([pendingSettingsAvatarBlob], "avatar.jpg", { type: "image/jpeg" });
                    avatarUrl = await uploadToImgBB(file) || '';
                    pendingSettingsAvatarBlob = null;
                }

                const fd = new FormData();
                fd.append('bio', document.getElementById('settingsBio').value);
                fd.append('avatar_url', avatarUrl);
                fd.append('bookmarks_public', document.getElementById('settingsBookmarksPublic').checked ? '1' : '0');
                fd.append('csrf_token', csrfToken);

                const res = await fetch(apiCall('update_profile'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Профиль обновлен');
                    await init();
                    if(window.location.pathname.includes('profile')) openProfileData(currentUser.id); 
                } else showToast(data.error || 'Ошибка');
            } finally { btn.textContent = orig; setFormState(form, false); isProcessing = false; }
        }

        async function saveAccount(e, phase = 'start', tempToken = '', code = '') {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;

            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = 'Сохранение...';

            try {
                let password = phase === 'start' ? prompt('Введите текущий пароль для подтверждения') : '';
                if (phase === 'start' && password === null) return;

                const fd = new FormData();
                fd.append('username', document.getElementById('accUsername').value);
                fd.append('email', document.getElementById('accEmail').value);
                fd.append('current_password', phase === 'start' ? (password || '') : '');
                fd.append('csrf_token', csrfToken);
                if (phase === 'verify') {
                    fd.append('temp_token', tempToken);
                    fd.append('code', code);
                }

                const res = await fetch(apiCall('update_account'), { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    showToast('Аккаунт сохранён');
                    await init();
                    if(window.location.pathname.includes('profile')) openProfileData(currentUser.id);
                } else if (data.require_email_verification) {
                    const userCode = prompt('Введите код из нового email для подтверждения смены');
                    if (userCode) await saveAccount(e, 'verify', data.temp_token, userCode);
                } else {
                    showToast(data.error || 'Ошибка сохранения');
                }
            } finally { btn.textContent = orig; setFormState(form, false); isProcessing = false; }
        }

        async function changePassword(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = 'Проверка...';

            try {
                const fd = new FormData();
                fd.append('current_password', document.getElementById('chPassCurrent').value);
                fd.append('new_password', document.getElementById('chPassNew').value);
                fd.append('confirm_password', document.getElementById('chPassConfirm').value);
                fd.append('csrf_token', csrfToken);

                const res = await fetch(apiCall('change_password'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Пароль успешно изменён');
                    closeModal('passwordModal');
                } else showToast(data.error || 'Ошибка смены пароля');
            } finally { btn.textContent = orig; setFormState(form, false); isProcessing = false; }
        }

        async function loadSessions() {
            const list = document.getElementById('sessionsList');
            list.innerHTML = '<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            try {
                const res = await fetch(apiCall('get_sessions'));
                const data = await res.json();
                if(data.sessions) {
                    list.innerHTML = data.sessions.map(s => {
                        let device = "Неизвестное устройство";
                        let isApp = s.user_agent.includes('DumpApp');
                        
                        if(isApp) device = "Мобильное приложение";
                        else if(s.user_agent.includes('Windows')) device = "Windows";
                        else if(s.user_agent.includes('Mac OS')) device = "MacOS";
                        else if(s.user_agent.includes('Linux') && !s.user_agent.includes('Android')) device = "Linux";
                        else if(s.user_agent.includes('Android')) device = "Android";
                        else if(s.user_agent.includes('iPhone') || s.user_agent.includes('iPad')) device = "iOS";
                        
                        let browser = "";
                        if(isApp) browser = "Dump";
                        else if(s.user_agent.includes('Chrome')) browser = "Chrome";
                        else if(s.user_agent.includes('Safari')) browser = "Safari";
                        else if(s.user_agent.includes('Firefox')) browser = "Firefox";
                        
                        const title = `${device} ${browser}`.trim() || 'Устройство';

                        return `
                        <div class="session-item smooth-fade-in" id="sess-${s.id}">
                            <div>
                                <div class="font-bold text-sm mb-1">${title} ${s.is_current ? '<span class="text-error ml-1 text-xs px-1" style="background:rgba(255,42,95,0.1); border-radius:4px;">Текущая</span>' : ''}</div>
                                <div class="text-xs text-muted transition cursor-help hover:text-white" onmouseover="this.innerText='IP: ${s.ip_address}'" onmouseout="this.innerText='IP: ${maskIp(s.ip_address)}'">IP: ${maskIp(s.ip_address)}</div>
                                <div class="text-xs text-muted mt-1">Вход: ${timeAgo(s.created_at)}</div>
                            </div>
                            ${!s.is_current ? `<button type="button" class="vc-btn-outline" style="padding: 6px 12px; border-radius: 8px; font-size:0.8rem; color:var(--error); border-color:rgba(255,42,95,0.3);" onclick="confirmRevokeSession('${s.id}')">Завершить</button>` : ''}
                        </div>`;
                    }).join('');
                }
            } catch(e) { list.innerHTML = '<div class="text-center py-4 text-error">Ошибка загрузки</div>'; }
        }

        function confirmRevokeSession(id) {
            showConfirm('Завершение сессии', 'Устройство будет отключено. Вы уверены?', () => revokeSession(id));
        }

        async function revokeSession(id) {
            const fd = new FormData(); fd.append('id', id); fd.append('csrf_token', csrfToken);
            const res = await fetch(apiCall('revoke_session'), { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                document.getElementById(`sess-${id}`).remove();
                showToast('Сессия завершена');
            } else showToast('Ошибка завершения сессии');
        }

        let searchTimeout = null;
        function openSearch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('searchResults').innerHTML = '<div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Что будем искать?</p></div>';
            openModal('searchModal', 'searchInput');
        }

        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 500);
        }

        async function performSearch() {
            const q = document.getElementById('searchInput').value.trim();
            const list = document.getElementById('searchResults');
            if(!q) { list.innerHTML = '<div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Что будем искать?</p></div>'; return; }
            
            list.innerHTML = '<div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            try {
                const res = await fetch(apiCall('search') + `&q=${encodeURIComponent(q)}`);
                const data = await res.json();
                
                if(data.posts.length === 0 && data.users.length === 0) {
                    list.innerHTML = '<div class="empty-state"><i class="ph ph-ghost"></i><p>Ничего не найдено</p></div>';
                    return;
                }
                
                let html = '';
                
                if(data.posts.length > 0) {
                    html += `<div class="search-section-title smooth-fade-in">Публикации</div>`;
                    html += data.posts.map(p => {
                        const snippet = p.content.length > 50 ? p.content.substring(0, 50) + '...' : p.content;
                        const firstImage = p.image_url ? p.image_url.split(',')[0] : null;
                        const cover = firstImage ? `<img src="${getProxyUrl(firstImage)}" class="search-result-post object-cover">` : `<div class="search-result-post flex items-center justify-center bg-surface-hover"><i class="ph ph-text-aa text-muted"></i></div>`;
                        return `
                        <div class="search-result-item smooth-fade-in mb-1" onclick="navigate('/post/${p.slug}'); closeModal('searchModal');">
                            ${cover}
                            <div class="flex-1 overflow-hidden">
                                <div class="font-bold text-sm truncate">${p.username}</div>
                                <div class="text-xs text-muted truncate">${snippet || 'Фото'}</div>
                            </div>
                        </div>`;
                    }).join('');
                }

                if(data.users.length > 0) {
                    html += `<div class="search-section-title smooth-fade-in mt-4">Пользователи</div>`;
                    html += data.users.map(u => `
                        <div class="search-result-item smooth-fade-in mb-1" onclick="navigate('/profile/${u.id}'); closeModal('searchModal');">
                            <img src="${getProxyUrl(u.avatar_url || 'https://ui-avatars.com/api/?name='+u.username+'&background=random')}" class="search-result-img">
                            <div class="font-bold flex-1">${u.username}</div>
                        </div>
                    `).join('');
                }
                
                list.innerHTML = html;
            } catch(e) { list.innerHTML = '<div class="text-error text-center py-4">Ошибка поиска</div>'; }
        }

        window.switchProfileTab = (tab) => {
            const isPosts = tab === 'posts';
            document.getElementById('tabBtnPosts').classList.toggle('active', isPosts);
            document.getElementById('tabBtnBookmarks').classList.toggle('active', !isPosts);
            
            const indicator = document.getElementById('profileTabIndicator');
            if (indicator) {
                indicator.style.transform = isPosts ? 'translateX(0)' : `translateX(${document.getElementById('tabBtnPosts').offsetWidth}px)`;
            }

            document.getElementById('profileGridPosts').classList.toggle('hidden', !isPosts);
            document.getElementById('profileGridBookmarks').classList.toggle('hidden', isPosts);
        };

        window.openLocalFeed = function(source, startIndex) {
            const posts = source === 'posts' ? window.currentProfilePosts : window.currentProfileBookmarks;
            if (!posts || !posts.length) return;

            switchView('feedView');
            
            const feedTabs = document.getElementById('feedTabs');
            if(feedTabs) feedTabs.classList.add('hidden');
            
            document.getElementById('navBackBtn').classList.remove('hidden');
            document.getElementById('navLogo').classList.add('hidden');

            const container = document.getElementById('feedView');
            container.innerHTML = '';
            
            const wrapper = document.createElement('div');
            wrapper.id = 'feedWrapper';
            wrapper.className = 'feed-wrapper';

            posts.forEach(post => {
                localBookmarksState[post.id] = post.is_bookmarked > 0;
                wrapper.appendChild(createPostElement(post));
                fetchCommentsForVibe(post.id);
            });

            const endCard = document.createElement('div');
            endCard.className = 'post-card smooth-fade-in';
            endCard.innerHTML = `<div class="empty-state"><i class="ph ph-check-circle" style="font-size:4rem; color:var(--text-muted);"></i><p class="mt-4" style="font-size:1.1rem; color:white;">Вы посмотрели все публикации</p></div>`;
            wrapper.appendChild(endCard);

            container.appendChild(wrapper);

            currentFeedIndex = startIndex;
            wrapper.style.transition = 'none';
            wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;

            const newPostSlug = posts[currentFeedIndex].slug;
            window.history.pushState({localFeed: true}, '', BASE_PATH + `/post/${newPostSlug}`);

            setTimeout(() => {
                wrapper.style.transition = 'transform 0.4s cubic-bezier(0.25, 1, 0.5, 1)';
                const initPostId = posts[currentFeedIndex].id;
                startFloatingComments(initPostId);
                markPostAsSeen(initPostId);
                initPostCarousel(currentFeedIndex);
            }, 50);
        };

        async function openProfileData(targetId) {
            const container = document.getElementById('profileView');
            const isMe = currentUser && targetId === currentUser.id;
            container.innerHTML = '<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 3.5rem; color: var(--text-muted);"></i></div>';
            
            try {
                const res = await fetch(apiCall('user_profile') + `&id=${targetId}`);
                const data = await res.json();
                const p = data.profile;
                const avatarUrl = getProxyUrl(p.avatar_url || `https://ui-avatars.com/api/?name=${p.username}&background=random`);
                
                window.currentProfilePosts = data.posts || [];
                window.currentProfileBookmarks = data.bookmarks || [];
                
                let actionBtnHTML = '';
                if (isMe) {
                    actionBtnHTML = `<button onclick="openSettings()" class="vc-btn vc-btn-outline flex items-center justify-center gap-2" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;"><i class="ph ph-gear"></i> Настройки</button>`;
                } else {
                    const isFollowed = p.is_followed > 0;
                    const writeBtn = isFollowed ? `<button onclick="navigate('/messages'); setTimeout(() => startNewChat(${p.id}, '${(p.username||'').replace(/'/g, "\\'")}', '${(p.avatar_url||'').replace(/'/g, "\\'")}'), 300);" class="vc-btn-outline flex items-center justify-center gap-2" style="padding: 8px 16px; width:auto; border-radius:99px; font-size:0.9rem;"><i class="ph ph-paper-plane-right"></i> Написать</button>` : '';
                    actionBtnHTML = `<div class="flex gap-2">${writeBtn}<button onclick="toggleFollow(${p.id}, this)" class="vc-btn ${isFollowed ? 'vc-btn-outline' : ''}" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;">${isFollowed ? 'Вы подписаны' : 'Подписаться'}</button></div>`;
                }

                const joinDate = new Date(p.created_at.replace(' ', 'T') + 'Z');
                const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
                const joinStr = `В Dump с ${months[joinDate.getMonth()]} ${joinDate.getFullYear()}`;

                const createGridHtml = (items, emptyMsg, emptyIcon, source) => {
                    if(!items || items.length === 0) return `<div class="empty-state smooth-fade-in" style="grid-column: span 3;"><i class="ph ${emptyIcon}"></i><p>${emptyMsg}</p></div>`;
                    let html = '';
                    items.forEach((post, index) => {
                        const hasImg = post.image_url && post.image_url !== '';
                        const isMultiple = hasImg && post.image_url.includes(',');
                        const firstImage = hasImg ? post.image_url.split(',')[0] : null;
                        const multiIcon = isMultiple ? '<i class="ph-fill ph-files multi-img-icon"></i>' : '';
                        const content = hasImg ? `${multiIcon}<img src="${getProxyUrl(firstImage)}" loading="lazy" onload="this.classList.add('loaded')">` : `<div class="text-preview p-2 flex items-center justify-center text-center w-full h-full text-xs">${post.content}</div>`;
                        html += `<div class="grid-item smooth-fade-in" onclick="openLocalFeed('${source}', ${index})">${content}</div>`;
                    });
                    return html;
                };

                let tabsHtml = '';
                let gridsHtml = `<div id="profileGridPosts" class="profile-grid">${createGridHtml(data.posts, 'Нет публикаций', 'ph-images', 'posts')}</div>`;

                if (isMe || data.profile.bookmarks_public == 1) {
                    tabsHtml = `
                    <div class="profile-tabs-wrapper smooth-fade-in">
                        <div class="profile-tabs">
                            <div id="profileTabIndicator" class="profile-tab-indicator"></div>
                            <button id="tabBtnPosts" class="profile-tab active" onclick="switchProfileTab('posts')"><i class="ph ph-grid-four"></i> Публикации</button>
                            <button id="tabBtnBookmarks" class="profile-tab" onclick="switchProfileTab('bookmarks')"><i class="ph ph-bookmark-simple"></i> Сохранённые</button>
                        </div>
                    </div>`;
                    gridsHtml += `<div id="profileGridBookmarks" class="profile-grid hidden">${createGridHtml(data.bookmarks, 'Нет сохранённых постов', 'ph-bookmark', 'bookmarks')}</div>`;
                }

                container.innerHTML = `
                    <div class="profile-header smooth-fade-in">
                        <img src="${avatarUrl}" class="profile-avatar">
                        <div class="w-full">
                            <h2 class="font-bold" style="font-size:1.4rem;">@${p.username}</h2>
                            <div class="profile-bio" style="margin: 6px 0 10px; font-size: 0.95rem; line-height: 1.5; word-break: break-word;">${formatBio(p.bio) || 'Нет информации.'}</div>
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 6px 12px; border-radius: 99px; background-color: var(--surface-hover); color: var(--text-muted); font-size: 0.8rem; font-weight: 500; margin-bottom: 1.5rem;"><i class="ph ph-calendar-blank"></i> ${joinStr}</div>
                            <div class="mb-4">${actionBtnHTML}</div>
                            <div class="profile-stats">
                                <div class="stat-item"><div class="stat-val">${p.posts_count}</div><div class="stat-lbl">Посты</div></div>
                                <div class="stat-item"><div class="stat-val" id="statFollowers">${p.followers_count}</div><div class="stat-lbl">Подписчики</div></div>
                                <div class="stat-item" ${isMe ? `style="cursor:pointer;" onclick="openFollowingModal(${p.id})"` : ''}><div class="stat-val">${p.following_count}</div><div class="stat-lbl">Подписки</div></div>
                            </div>
                        </div>
                    </div>
                    ${tabsHtml}
                    ${gridsHtml}
                `;
            } catch(e) { container.innerHTML = `<div class="empty-state"><p>Ошибка загрузки профиля</p></div>`; }
        }

        async function toggleFollow(userId, btn) {
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            const fd = new FormData(); fd.append('id', userId); fd.append('csrf_token', csrfToken);
            const res = await fetch(apiCall('toggle_follow'), { method: 'POST', body: fd });
            const data = await res.json();
            const followersEl = document.getElementById('statFollowers');
            
            if (data.followed) {
                btn.className = 'vc-btn vc-btn-outline'; btn.textContent = 'Вы подписаны';
                if(followersEl) followersEl.textContent = parseInt(followersEl.textContent) + 1;
            } else {
                btn.className = 'vc-btn'; btn.textContent = 'Подписаться';
                if(followersEl) followersEl.textContent = parseInt(followersEl.textContent) - 1;
            }
            isProcessing = false;
        }

        async function openFollowingModal(userId) {
            if (!userId) userId = currentUser.id;
            const list = document.getElementById('followingList');
            list.innerHTML = '<div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            openModal('followingModal');
            try {
                const res = await fetch(apiCall('get_following') + `&id=${userId}`);
                const data = await res.json();
                if (data.success && data.following.length > 0) {
                    list.innerHTML = data.following.map(u => {
                        const avatar = getProxyUrl(u.avatar_url || `https://ui-avatars.com/api/?name=${u.username}&background=random`);
                        return `<div class="search-result-item smooth-fade-in" onclick="navigate('/profile/${u.id}'); closeModal('followingModal');">
                            <img src="${avatar}" class="search-result-img">
                            <div class="font-bold flex-1">${u.username}</div>
                        </div>`;
                    }).join('');
                } else if (data.success) {
                    list.innerHTML = '<div class="empty-state"><i class="ph ph-user-circle"></i><p>Нет подписок</p></div>';
                } else {
                    list.innerHTML = '<div class="text-center py-4 text-muted">Ошибка загрузки</div>';
                }
            } catch(e) {
                list.innerHTML = '<div class="text-center py-4 text-muted">Ошибка загрузки</div>';
            }
        }
        window.openFollowingModal = openFollowingModal;

        let notifOffset = 0;
        let notifLoading = false;
        let notifHasMore = true;
        let notifAllData = [];
        let lastKnownNotifId = 0;

        function getNotifText(type, username) {
            switch(type) {
                case 'like': return `<b>${username}</b> поставил(а) лайк на ваш пост`;
                case 'comment': return `<b>${username}</b> написал(а) комментарий к вашему посту`;
                case 'follow': return `<b>${username}</b> подписался(-ась) на вас`;
                case 'new_post': return `<b>${username}</b> опубликовал(а) новый пост`;
                case 'login': return `Выполнен вход в ваш аккаунт`;
                case 'mention': return `<b>${username}</b> упомянул(а) вас в публикации`;
                default: return 'Новое уведомление';
            }
        }

        function getNotifIcon(type) {
            switch(type) {
                case 'like': return 'ph-fill ph-heart';
                case 'comment': return 'ph ph-chat-circle';
                case 'follow': return 'ph ph-user-plus';
                case 'new_post': return 'ph ph-article';
                case 'login': return 'ph ph-sign-in';
                case 'mention': return 'ph ph-at';
                default: return 'ph ph-bell';
            }
        }

        function getNotifIconColor(type) {
            switch(type) {
                case 'like': return 'var(--error)';
                case 'comment': return '#60a5fa';
                case 'follow': return '#34d399';
                case 'new_post': return '#f5a623';
                case 'login': return '#a78bfa';
                case 'mention': return '#ffffff';
                default: return 'var(--text-muted)';
            }
        }

        function handleNotifClick(n) {
            if (n.type === 'comment') {
                if (n.post_slug) {
                    navigate('/post/' + n.post_slug);
                    setTimeout(() => {
                        const postCard = document.querySelector(`.post-card[data-slug="${n.post_slug}"]`);
                        if (postCard) {
                            const postId = postCard.dataset.id;
                            openComments(parseInt(postId), n.post_slug);
                        }
                    }, 600);
                }
            } else if (n.type === 'like') {
                if (n.from_id) navigate('/profile/' + n.from_id);
            } else if (n.type === 'follow') {
                if (n.from_id) navigate('/profile/' + n.from_id);
            } else if (n.type === 'new_post') {
                if (n.post_slug) navigate('/post/' + n.post_slug);
                else if (n.from_id) navigate('/profile/' + n.from_id);
            } else if (n.type === 'mention') {
                if (n.post_slug) navigate('/post/' + n.post_slug);
            } else if (n.type === 'login') {
                navigate('/profile');
                setTimeout(() => { openSettings(); setTimeout(() => switchSettingsTab('sessions'), 100); }, 300);
            }
        }

        async function loadNotifications() {
            if (!currentUser) return;
            const list = document.getElementById('notificationsList');
            notifOffset = 0;
            notifHasMore = true;
            notifAllData = [];
            list.innerHTML = '<div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            try {
                const res = await fetch(apiCall('get_notifications') + '&offset=0&limit=20');
                const data = await res.json();
                if (data.success) {
                    notifAllData = data.notifications || [];
                    notifOffset = notifAllData.length;
                    notifHasMore = data.notifications.length >= 20;
                    renderNotificationsList(notifAllData);
                    if (data.unread_count > 0) {
                        fetch(apiCall('mark_notifications_read'), { method: 'POST', body: 'csrf_token=' + encodeURIComponent(csrfToken), headers: {'Content-Type': 'application/x-www-form-urlencoded'} });
                        updateNotifBadge(0);
                    }
                    if (notifAllData.length > 0) lastKnownNotifId = notifAllData[0].id;
                    setupNotifScrollListener();
                }
            } catch(e) {
                list.innerHTML = '<div class="empty-state"><i class="ph ph-warning"></i><p>Ошибка загрузки</p></div>';
            }
        }

        async function loadMoreNotifications() {
            if (!currentUser || notifLoading || !notifHasMore) return;
            notifLoading = true;
            const loader = document.getElementById('notifLoadMore');
            if (loader) loader.classList.remove('hidden');
            try {
                const res = await fetch(apiCall('get_notifications') + `&offset=${notifOffset}&limit=20`);
                const data = await res.json();
                if (data.success && data.notifications.length > 0) {
                    notifAllData = notifAllData.concat(data.notifications);
                    notifOffset += data.notifications.length;
                    notifHasMore = data.notifications.length >= 20;
                    appendNotifications(data.notifications);
                } else {
                    notifHasMore = false;
                }
            } catch(e) {}
            notifLoading = false;
            if (loader) loader.classList.add('hidden');
        }

        function setupNotifScrollListener() {
            const list = document.getElementById('notificationsList');
            if (!list) return;
            list.onscroll = () => {
                if (list.scrollTop + list.clientHeight >= list.scrollHeight - 100) {
                    loadMoreNotifications();
                }
            };
        }

        function renderNotificationsList(notifications) {
            const list = document.getElementById('notificationsList');
            if (!notifications || notifications.length === 0) {
                list.innerHTML = '<div class="empty-state smooth-fade-in"><i class="ph ph-bell-slash"></i><p>Нет уведомлений</p></div>';
                return;
            }
            window._notifData = {};
            let html = notifications.map((n, i) => {
                window._notifData[i] = n;
                return renderNotifItem(n, i);
            }).join('');
            html += '<div id="notifLoadMore" class="hidden" style="text-align:center;padding:1rem;"><i class="ph ph-circle-notch spin" style="font-size:1.5rem;color:var(--text-muted);"></i></div>';
            list.innerHTML = html;
            attachNotifListeners(list);
        }

        function appendNotifications(notifications) {
            const list = document.getElementById('notificationsList');
            const loader = document.getElementById('notifLoadMore');
            const startIdx = notifAllData.length - notifications.length;
            notifications.forEach((n, i) => {
                window._notifData[startIdx + i] = n;
                const div = document.createElement('div');
                div.innerHTML = renderNotifItem(n, startIdx + i);
                const item = div.firstElementChild;
                if (loader) list.insertBefore(item, loader);
                else list.appendChild(item);
                attachNotifSwipe(item);
            });
        }

        function renderNotifItem(n, idx) {
            const avatar = n.from_avatar_url ? getProxyUrl(n.from_avatar_url) : (n.from_username ? getProxyUrl('https://ui-avatars.com/api/?name=' + n.from_username + '&background=random') : '');
            const iconClass = getNotifIcon(n.type);
            const iconColor = getNotifIconColor(n.type);
            const text = getNotifText(n.type, n.from_username || 'Кто-то');
            const unreadClass = n.is_read == 0 ? 'notif-unread' : '';
            const avatarHtml = avatar ? `<img src="${avatar}" class="notif-avatar" loading="lazy">` : `<div class="notif-avatar-placeholder"><i class="${iconClass}" style="color:${iconColor};font-size:1.2rem;"></i></div>`;
            return `<div class="notif-item smooth-fade-in ${unreadClass}" data-notif-idx="${idx}" data-notif-id="${n.id}">
                ${avatarHtml}
                <div class="notif-body">
                    <div class="notif-text">${text}</div>
                    <div class="notif-time">${timeAgo(n.created_at)}</div>
                </div>
                <div class="notif-icon-indicator" style="color:${iconColor};"><i class="${iconClass}"></i></div>
            </div>`;
        }

        function attachNotifListeners(list) {
            list.querySelectorAll('.notif-item').forEach(el => {
                attachNotifSwipe(el);
            });
        }

        function attachNotifSwipe(el) {
            let startX = 0, curX = 0, swiping = false;
            const getX = (e) => e.touches ? e.touches[0].clientX : e.clientX;
            const onStart = (e) => {
                startX = getX(e);
                swiping = false;
                el.style.transition = 'none';
            };
            const onMove = (e) => {
                if (e.buttons !== undefined && e.buttons !== 1) return;
                curX = getX(e);
                const diff = curX - startX;
                if (Math.abs(diff) > 5) swiping = true;
                if (diff > 0) {
                    el.style.transform = `translateX(${diff}px)`;
                    el.style.opacity = Math.max(0, 1 - diff / 200);
                }
            };
            const onEnd = () => {
                const diff = curX - startX;
                el.style.transition = 'transform 0.3s, opacity 0.3s';
                if (swiping && diff > 80) {
                    el.style.transform = 'translateX(100%)';
                    el.style.opacity = '0';
                    const notifId = el.dataset.notifId;
                    if (notifId) {
                        fetch(apiCall('delete_notification'), { method: 'POST', body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&id=' + notifId, headers: {'Content-Type': 'application/x-www-form-urlencoded'} });
                    }
                    setTimeout(() => el.remove(), 300);
                } else if (!swiping) {
                    const idx = parseInt(el.dataset.notifIdx);
                    if (window._notifData && window._notifData[idx]) handleNotifClick(window._notifData[idx]);
                } else {
                    el.style.transform = '';
                    el.style.opacity = '';
                }
                swiping = false;
            };
            el.addEventListener('touchstart', onStart, {passive: true});
            el.addEventListener('touchmove', onMove, {passive: true});
            el.addEventListener('touchend', onEnd);
            el.addEventListener('mousedown', onStart);
            el.addEventListener('mousemove', onMove);
            el.addEventListener('mouseup', onEnd);
            el.addEventListener('mouseleave', () => { if (swiping) { el.style.transform = ''; el.style.opacity = ''; swiping = false; } });
        }

        function updateNotifBadge(count) {
            const badge = document.getElementById('notifBadge');
            const profileIcon = document.querySelector('[data-nav="profile"] i');
            _notifCount = count;
            const t = document.title.replace(/^\(\d+\+?\)\s*/, '');
            document.title = count > 0 ? `(${count > 9 ? '9+' : count}) ${t}` : t;
            if (count > 0) {
                if (badge) { badge.textContent = count > 99 ? '99+' : count; badge.classList.remove('hidden'); }
                if (profileIcon) profileIcon.classList.add('notif-pulse');
            } else {
                if (badge) badge.classList.add('hidden');
                if (profileIcon) profileIcon.classList.remove('notif-pulse');
            }
        }

        function playNotifSound() {
            if (isDumpApp) return;
            try {
                var a = document.getElementById('notifAudio');
                if (!a) return;
                a.currentTime = 0;
                a.play();
            } catch(e) {
                console.error('playNotifSound:', e);
            }
        }

        let _prevUnread = -1;

        async function pollUnreadCount() {
            if (!currentUser) return;
            try {
                const res = await fetch(apiCall('get_unread_count') + '&last_id=' + lastKnownNotifId);
                const data = await res.json();
                if (data.success) {
                    updateNotifBadge(data.unread_count);
                    if (_prevUnread >= 0 && data.unread_count > _prevUnread) {
                        playNotifSound();
                    }
                    _prevUnread = data.unread_count;
                    if (data.new_notifications && data.new_notifications.length > 0) {
                        data.new_notifications.forEach(n => {
                            const text = getNotifText(n.type, n.from_username || 'Кто-то').replace(/<[^>]*>/g, '');
                            showToast(text);
                        });
                        lastKnownNotifId = data.new_notifications[0].id;
                    }
                }
            } catch(e) {}
        }

        function startNotifPolling() {
            stopNotifPolling();
            pollUnreadCount();
            notifPollInterval = setInterval(pollUnreadCount, 5000);
        }

        function stopNotifPolling() {
            if (notifPollInterval) { clearInterval(notifPollInterval); notifPollInterval = null; }
            updateNotifBadge(0);
        }

        function processPostImageFiles(files) {
            if (!files.length) return;
            
            let validFiles = [];
            const signatures = new Set();

            for(let f of files) {
                if (f.size > MAX_FILE_SIZE) { showToast(`Файл ${f.name} слишком большой (макс 20 МБ)`); continue; }
                if (!f.type.startsWith('image/')) { showToast(`Файл ${f.name} не картинка`); continue; }
                
                const sig = f.name + f.size;
                if(signatures.has(sig)) continue;
                signatures.add(sig);

                validFiles.push(f);
            }

            if(validFiles.length > 5) {
                showToast("Максимум 5 изображений");
                validFiles = validFiles.slice(0, 5);
            }

            pendingPostImageFiles = [...pendingPostImageFiles, ...validFiles].slice(0, 5);
            renderMultiImagePreview();
        }

        function handleDropPhotos(e) {
            if(e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                processPostImageFiles(Array.from(e.dataTransfer.files));
            }
        }

        function handlePastePostImages(e) {
            if (!e.clipboardData || !e.clipboardData.items) return;
            
            const items = Array.from(e.clipboardData.items);
            const files = [];
            
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    const file = item.getAsFile();
                    if (file) files.push(file);
                }
            }
            
            if (files.length > 0) {
                e.preventDefault();
                processPostImageFiles(files);
            }
        }

        function handleMultiplePostImages(e) {
            processPostImageFiles(Array.from(e.target.files));
            e.target.value = '';
        }

        function renderMultiImagePreview() {
            const uploadZone = document.getElementById('uploadZone');
            const multiContainer = document.getElementById('multiImagePreviewContainer');

            if (pendingPostImageFiles.length === 0) {
                multiContainer.classList.add('hidden');
                multiContainer.innerHTML = '';
                uploadZone.classList.remove('hidden');
                return;
            }

            uploadZone.classList.add('hidden');
            multiContainer.classList.remove('hidden');

            if (pendingPostImageFiles.length === 1) {
                const url = URL.createObjectURL(pendingPostImageFiles[0]);
                multiContainer.innerHTML = `
                    <div class="big-preview smooth-fade-in">
                        <img src="${url}">
                        <div class="remove-btn" onclick="event.stopPropagation(); removePendingImage(0)"><i class="ph ph-x"></i></div>
                        <div class="overlay-btn" onclick="document.getElementById('postImageUpload').click()"><i class="ph ph-plus" style="font-size: 3rem; color: white; filter: drop-shadow(0 2px 10px rgba(0,0,0,0.5));"></i></div>
                    </div>
                `;
            } else {
                let html = '<div class="preview-grid smooth-fade-in">';
                pendingPostImageFiles.forEach((file, index) => {
                    const url = URL.createObjectURL(file);
                    html += `
                        <div class="preview-item">
                            <img src="${url}">
                            <div class="remove-btn" onclick="removePendingImage(${index})"><i class="ph ph-x"></i></div>
                        </div>
                    `;
                });
                if (pendingPostImageFiles.length < 5) {
                    html += `<div class="add-more-grid-item" onclick="document.getElementById('postImageUpload').click()"><i class="ph ph-plus"></i></div>`;
                }
                html += '</div>';
                multiContainer.innerHTML = html;
            }
        }

        function removePendingImage(index) {
            pendingPostImageFiles.splice(index, 1);
            document.getElementById('postImageUpload').value = ''; 
            renderMultiImagePreview();
        }

        function handleCreatePostSubmit(e) {
            e.preventDefault();
            if(!requireAuthClient()) return;
            
            const content = document.getElementById('postContent').value.trim();
            if(content === '' && pendingPostImageFiles.length > 0) {
                openModal('textWarningModal');
                return;
            }
            
            forceCreatePost();
        }

        async function forceCreatePost() {
            closeModal('textWarningModal');
            if (isProcessing) return;
            isProcessing = true;
            
            const form = document.getElementById('createPostForm');
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = '<i class="ph ph-circle-notch spin"></i>';
            
            try {
                let uploadedUrls = [];
                if (pendingPostImageFiles.length > 0) {
                    btn.innerHTML = `<span style="font-size:0.9rem;">Загрузка фото...</span>`;
                    for(let file of pendingPostImageFiles) {
                        const url = await uploadToImgBB(file);
                        if(url) uploadedUrls.push(url);
                    }
                }

                const fd = new FormData();
                fd.append('content', document.getElementById('postContent').value);
                fd.append('image_url', [...new Set(uploadedUrls)].join(',')); 
                fd.append('csrf_token', csrfToken);

                btn.innerHTML = '<i class="ph ph-circle-notch spin"></i>';
                const res = await fetch(apiCall('create_post'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    document.getElementById('postContent').value = '';
                    pendingPostImageFiles = [];
                    renderMultiImagePreview();
                    showToast('Успешно опубликовано'); 
                    fireConfetti();
                    closeCreatePost();
                    if(window.location.pathname === BASE_PATH + '/' || window.location.pathname === BASE_PATH) loadFeed();
                } else showToast(data.error || 'Ошибка публикации');
            } catch(e) {
                showToast("Произошла ошибка");
            } finally { 
                btn.textContent = orig; 
                setFormState(form, false); 
                isProcessing = false; 
            }
        }

        function initTabIndicator() {
            const tabAll = document.getElementById('tab-all');
            const tabFollowing = document.getElementById('tab-following');
            const indicator = document.getElementById('tabIndicator');
            
            if(!tabAll || !indicator) return;
            
            const activeTab = activeFeedType === 'all' ? tabAll : tabFollowing;
            indicator.style.width = activeTab.offsetWidth + 'px';
            indicator.style.transform = `translateX(${activeTab.offsetLeft - 4}px)`; 
        }

        function setFeedType(type) {
            activeFeedType = type;
            document.getElementById('tab-all').classList.toggle('active', type === 'all');
            document.getElementById('tab-following').classList.toggle('active', type === 'following');
            initTabIndicator();
            loadFeed();
        }

        window.handleFeedTabClick = function(type) {
            if (activeFeedType === type) {
                loadFeed();
                showToast('Лента обновлена');
            } else {
                setFeedType(type);
            }
        };

        async function loadFeed() {
            const container = document.getElementById('feedView');
            container.innerHTML = `<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 3.5rem; color: var(--text-muted);"></i></div>`;
            
            let currentSlug = '';
            const path = window.location.pathname;
            if (path.includes('/post/')) {
                currentSlug = path.split('/').pop();
            }
            
            try {
                let excludeParam = '';
                if (!currentUser) {
                    const viewed = getGuestViewed();
                    if (viewed.length) excludeParam = '&exclude=' + viewed.slice(-500).join(',');
                }
                const res = await fetch(apiCall('posts') + `&type=${activeFeedType}&slug=${currentSlug}${excludeParam}`);
                const data = await res.json();
                
                if (data.error) throw new Error(data.error);
                
                const posts = data.posts || [];
                if(posts.length === 0) {
                    const isFollowing = activeFeedType === 'following';
                    const msg = isFollowing ? 'Вы еще ни на кого не подписаны' : 'Вы посмотрели все новые посты. Загляните позже!';
                    const icon = isFollowing ? 'ph-ghost' : 'ph-check-circle';
                    container.innerHTML = `<div class="empty-state smooth-fade-in"><i class="ph ${icon}" style="color:var(--text-muted); font-size:4rem;"></i><p class="mt-4" style="font-size:1.1rem; color:white;">${msg}</p></div>`;
                    return;
                }
                
                container.innerHTML = '';
                const wrapper = document.createElement('div');
                wrapper.id = 'feedWrapper';
                wrapper.className = 'feed-wrapper';
                
                let initialIndex = 0;
                if (currentSlug) {
                    const idx = posts.findIndex(p => p.slug === currentSlug);
                    if (idx !== -1) initialIndex = idx;
                }

                posts.forEach(post => { 
                    localBookmarksState[post.id] = post.is_bookmarked > 0;
                    wrapper.appendChild(createPostElement(post)); 
                    fetchCommentsForVibe(post.id); 
                });
                
                const endCard = document.createElement('div');
                endCard.className = 'post-card smooth-fade-in';
                endCard.innerHTML = `<div class="empty-state"><i class="ph ph-check-circle" style="font-size:4rem; color:var(--text-muted);"></i><p class="mt-4" style="font-size:1.1rem; color:white;">Вы посмотрели все новые посты</p><p class="text-muted mt-2 text-sm">Возвращайтесь позже за свежими обновлениями</p></div>`;
                wrapper.appendChild(endCard);

                container.appendChild(wrapper);

                currentFeedIndex = initialIndex;
                wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;
                
                const initPostId = posts[currentFeedIndex]?.id;
                if (initPostId) {
                    startFloatingComments(initPostId);
                    markPostAsSeen(initPostId); 
                }
                
                initPostCarousel(currentFeedIndex);
                preloadFeedImages(currentFeedIndex);

            } catch(e) { 
                console.error(e);
                container.innerHTML = `<div class="empty-state smooth-fade-in"><p>Ошибка загрузки ленты</p></div>`; 
            }
        }

        function sharePost(slug) {
            const url = window.location.origin + BASE_PATH + '/post/' + slug;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url)
                    .then(() => showToast('Ссылка на пост скопирована'))
                    .catch(() => fallbackCopyTextToClipboard(url));
            } else {
                fallbackCopyTextToClipboard(url);
            }
        }
        
        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.top = "0"; textArea.style.left = "0"; textArea.style.position = "fixed";
            document.body.appendChild(textArea);
            textArea.focus(); textArea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) showToast('Ссылка на пост скопирована');
                else showToast('Не удалось скопировать ссылку');
            } catch (err) { showToast('Не удалось скопировать ссылку'); }
            document.body.removeChild(textArea);
        }

        let currentOptionsPostId = null;
        let currentOptionsPostSlug = null;
        
        function openPostOptions(postId, slug, isMyPost) {
            currentOptionsPostId = postId;
            currentOptionsPostSlug = slug;
            
            const isBookmarked = localBookmarksState[postId];
            const bBtnText = document.getElementById('poBookmarkText');
            const bBtnIcon = document.querySelector('#poBookmarkBtn i');
            
            if (isBookmarked) {
                bBtnText.textContent = 'Убрать из сохранённого';
                bBtnIcon.className = 'ph-fill ph-bookmark text-warning';
            } else {
                bBtnText.textContent = 'Сохранить пост';
                bBtnIcon.className = 'ph ph-bookmark';
            }

            const delBtn = document.getElementById('poDeleteBtn');
            if (isMyPost) delBtn.classList.remove('hidden');
            else delBtn.classList.add('hidden');

            openModal('postOptionsModal');
        }

        async function doBookmarkFromOptions() {
            if(!requireAuthClient()) return;
            const postId = currentOptionsPostId;
            closeModal('postOptionsModal');
            
            try {
                const fd = new FormData(); fd.append('post_id', postId); fd.append('csrf_token', csrfToken);
                const res = await fetch(apiCall('toggle_bookmark'), { method: 'POST', body: fd });
                const data = await res.json();
                
                localBookmarksState[postId] = data.bookmarked;
                showToast(data.bookmarked ? 'Пост сохранён' : 'Пост убран из сохранённого');
            } catch(e) {}
        }
        
        function doShareFromOptions() {
            const slug = currentOptionsPostSlug;
            closeModal('postOptionsModal');
            sharePost(slug);
        }
        
        function doDeleteFromOptions() {
            const postId = currentOptionsPostId;
            closeModal('postOptionsModal');
            const card = document.querySelector(`.post-card[data-id="${postId}"]`);
            showConfirm('Удаление', 'Точно удалить этот пост?', () => deletePost(postId, card));
        }

        function doDownloadFromOptions() {
            const postId = currentOptionsPostId;
            closeModal('postOptionsModal');
            const card = document.querySelector(`.post-card[data-id="${postId}"]`);
            if (!card) { showToast('Пост не найден'); return; }
            const wrapper = card.querySelector('.post-wrapper');
            if (!wrapper) { showToast('Не удалось найти содержимое'); return; }
            downloadPostWithWatermark(postId, wrapper);
        }

        async function downloadPostWithWatermark(postId, wrapper) {
            const slider = wrapper?.querySelector('.image-slider');
            let imgSrc = null;
            if (slider && slider.children.length > 0) {
                const transform = slider.style.transform || '';
                const m = transform.match(/translateX\(-(\d+)%/);
                const idx = m ? Math.min(parseInt(m[1]) / 100, slider.children.length - 1) : 0;
                imgSrc = slider.children[idx]?.src || slider.children[idx]?.getAttribute('src');
            } else {
                const img = wrapper?.querySelector('.post-img');
                if (img) imgSrc = img?.src || img?.getAttribute('src');
            }
            if (!imgSrc) {
                const txt = wrapper?.querySelector('.post-text-content');
                if (txt) { downloadTextPost(postId, txt); return; }
                showToast('Изображение недоступно');
                return;
            }

            if (typeof Android !== 'undefined' && Android.downloadImage) {
                showToast('Скачивание...');
                Android.downloadImage(imgSrc, `dump_${postId}.jpg`);
                return;
            }

            showToast('Подготовка...');
            try {
                const resp = await fetch(imgSrc);
                const blob = await resp.blob();
                const img = await new Promise((resolve, reject) => {
                    const i = new Image();
                    i.onload = () => resolve(i);
                    i.onerror = reject;
                    i.src = URL.createObjectURL(blob);
                });
                addWatermarkAndDownload(img, postId);
            } catch (e) {
                showToast('Скачивание без водяного знака...');
                const a = document.createElement('a');
                a.href = imgSrc;
                a.download = `dump_${postId}.jpg`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        }

        function addWatermarkAndDownload(img, postId) {
            const wh = 36;
            const c = document.createElement('canvas');
            c.width = img.naturalWidth;
            c.height = img.naturalHeight + wh;
            const ctx = c.getContext('2d');
            ctx.drawImage(img, 0, 0);
            ctx.fillStyle = 'rgba(0,0,0,0.75)';
            ctx.fillRect(0, img.naturalHeight, c.width, wh);
            ctx.fillStyle = 'rgba(255,255,255,0.7)';
            ctx.font = `${Math.round(wh * 0.48)}px system-ui,sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('dump.press', c.width / 2, img.naturalHeight + wh / 2);
            c.toBlob((blob) => {
                if (!blob) return;
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `dump_${postId}.jpg`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
            }, 'image/jpeg', 0.92);
        }

        function downloadTextPost(postId, el) {
            if (typeof Android !== 'undefined' && Android.downloadImage) {
                showToast('Скачивание текста не поддерживается');
                return;
            }
            showToast('Подготовка...');
            const w = 800, h = 600;
            const c = document.createElement('canvas');
            c.width = w;
            c.height = h;
            const ctx = c.getContext('2d');
            ctx.fillStyle = '#1a1a1a';
            ctx.fillRect(0, 0, w, h);
            ctx.fillStyle = 'rgba(255,255,255,0.92)';
            const text = el.textContent || '';
            if (!text.trim()) { showToast('Пост пуст'); return; }
            const fs = text.length < 100 ? 28 : (text.length < 300 ? 20 : 16);
            ctx.font = `600 ${fs}px system-ui,sans-serif`;
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            const pad = 40;
            const maxW = w - pad * 2;
            const lines = text.split('\n').filter(l => l.trim());
            const wrapped = [];
            for (const line of lines) {
                let measured = '';
                for (const ch of line) {
                    if (ctx.measureText(measured + ch).width > maxW) {
                        wrapped.push(measured);
                        measured = ch;
                    } else {
                        measured += ch;
                    }
                }
                if (measured) wrapped.push(measured);
            }
            const lh = fs * 1.5;
            const total = wrapped.length * lh;
            const startY = Math.max(pad, (h - total) / 2);
            wrapped.forEach((line, i) => ctx.fillText(line, pad, startY + i * lh));
            const wh = 36;
            const c2 = document.createElement('canvas');
            c2.width = w;
            c2.height = h + wh;
            const ctx2 = c2.getContext('2d');
            ctx2.drawImage(c, 0, 0);
            ctx2.fillStyle = 'rgba(0,0,0,0.75)';
            ctx2.fillRect(0, h, w, wh);
            ctx2.fillStyle = 'rgba(255,255,255,0.7)';
            ctx2.font = `${Math.round(wh * 0.48)}px system-ui,sans-serif`;
            ctx2.textAlign = 'center';
            ctx2.textBaseline = 'middle';
            ctx2.fillText('dump.press', w / 2, h + wh / 2);
            c2.toBlob((blob) => {
                if (!blob) return;
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `dump_${postId}.jpg`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
            }, 'image/jpeg', 0.92);
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function createPostElement(post) {
            const div = document.createElement('div');
            div.className = 'post-card smooth-fade-in'; div.dataset.id = post.id; div.dataset.slug = post.slug;
            const avatar = getProxyUrl(post.avatar_url || `https://ui-avatars.com/api/?name=${post.username}&background=random`);
            const hasImages = post.image_url && post.image_url !== '';
            const isMyPost = currentUser && post.user_id === currentUser.id;
            const isLongText = post.content && post.content.length > 250;
            const safeContent = post.content ? linkify(post.content) : '';
            
            let contentHtml = '';
            
            if (hasImages) {
                const images = post.image_url.split(',');
                
                contentHtml += `<div class="absolute inset-0 bg-surface" style="z-index: 0;"></div>`;
                
                if (images.length === 1) {
                    contentHtml += `<img src="${getProxyUrl(images[0])}" class="post-img relative z-10" style="object-fit: contain; width: 100%; height: 100%; pointer-events: none;" loading="lazy" decoding="async" onload="this.classList.add('loaded')">`;
                } else {
                    let slidesHtml = '';
                    let dotsHtml = '';
                    images.forEach((img, idx) => {
                        slidesHtml += `<img src="${getProxyUrl(img)}" class="slider-img" loading="lazy" decoding="async" onload="this.classList.add('loaded')">`;
                        dotsHtml += `<div class="slider-dot"></div>`;
                    });
                    contentHtml += `
                        <div class="image-slider">${slidesHtml}</div>
                        <div class="slider-dots">${dotsHtml}</div>
                    `;
                }

                if (post.content) {
                    contentHtml += `<div class="post-overlay-bottom relative z-20 ${isLongText ? 'scrollable-overlay' : ''}">${safeContent}</div>`;
                }
            } else {
                const fontSize = post.content.length < 100 ? '2rem' : (post.content.length < 300 ? '1.4rem' : '1.1rem');
                contentHtml = `<div class="post-text-content w-full h-full flex items-center justify-center p-6 text-center relative z-10" style="font-size: ${fontSize}; overflow-y: auto;">${safeContent}</div>`;
            }

            div.innerHTML = `
                <div class="post-wrapper" ondblclick="handleDoubleTap(${post.id}, this)">
                    <div id="floatArea-${post.id}" style="position:absolute; inset:0; overflow:hidden; pointer-events:none; z-index:0;"></div>
                    ${contentHtml}
                    <div class="post-author" onclick="navigate('/profile/${post.user_id}')">
                        <img src="${avatar}" loading="lazy"><span class="author-name">${post.username}</span><span class="post-time">• ${timeAgo(post.created_at)}</span>
                    </div>
                    <div class="post-actions">
                        <div class="action-btn" onclick="toggleLike(${post.id}, this)">
                            <div class="icon-bg"><i class="${post.is_liked > 0 ? 'ph-fill text-error' : 'ph'} ph-heart" id="likeIcon-${post.id}" style="${post.is_liked > 0 ? 'color: var(--error);' : ''}"></i></div>
                            <span id="likeCount-${post.id}">${post.likes_count}</span>
                        </div>
                        <div class="action-btn" onclick="openComments(${post.id}, '${post.slug}')">
                            <div class="icon-bg"><i class="ph ph-chat-circle"></i></div>
                            <span id="commentCount-${post.id}">${post.comments_count}</span>
                        </div>
                        <div class="action-btn" onclick="openPostOptions(${post.id}, '${post.slug}', ${isMyPost})">
                            <div class="icon-bg"><i class="ph ph-dots-three"></i></div>
                        </div>
                    </div>
                </div>
            `;
            return div;
        }

        let tapTimeout = null;
        function handleDoubleTap(postId, el) {
            if(!requireAuthClient()) return;
            if (tapTimeout) return;
            let heart = el.querySelector('.double-tap-heart');
            if(!heart) { heart = document.createElement('i'); heart.className = 'ph-fill ph-heart double-tap-heart'; el.appendChild(heart); }
            heart.classList.remove('animating'); void heart.offsetWidth; heart.classList.add('animating');
            const icon = document.getElementById(`likeIcon-${postId}`);
            if(!icon.classList.contains('ph-fill')) toggleLike(postId, el.querySelector('.action-btn'), true);
            tapTimeout = setTimeout(() => { tapTimeout = null; }, 1000);
        }

        async function deletePost(postId, elementContext) {
            const fd = new FormData(); fd.append('post_id', postId); fd.append('csrf_token', csrfToken);
            try {
                const res = await fetch(apiCall('delete_post'), { method: 'POST', body: fd });
                const data = await res.json();
                if(data.success) {
                    showToast('Пост удален');
                    const card = elementContext && elementContext.classList.contains('post-card') ? elementContext : elementContext?.closest('.post-card');
                    if(card) {
                        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease'; 
                        card.style.opacity = '0'; 
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            card.remove();
                            if(document.querySelectorAll('.post-card').length <= 1) navigate('/', true);
                        }, 300);
                    } else {
                        navigate('/', true);
                    }
                } else showToast(data.error || 'Ошибка удаления');
            } catch(e) { showToast('Ошибка удаления'); }
        }

        let isLiking = false;
        async function toggleLike(postId, btn, forceLike = false) {
            if(!requireAuthClient()) return;
            if (isLiking) return; isLiking = true;
            const icon = document.getElementById(`likeIcon-${postId}`), countEl = document.getElementById(`likeCount-${postId}`);
            let count = parseInt(countEl.textContent); const isLiked = icon.classList.contains('ph-fill');
            if (forceLike && isLiked) { isLiking = false; return; }
            if (isLiked) { icon.className = 'ph ph-heart'; icon.style.color = 'inherit'; countEl.textContent = count - 1; } 
            else { icon.className = 'ph-fill ph-heart'; icon.style.color = 'var(--error)'; countEl.textContent = count + 1; }
            try {
                const fd = new FormData(); fd.append('post_id', postId); fd.append('csrf_token', csrfToken);
                await fetch(apiCall('toggle_like'), { method: 'POST', body: fd });
            } finally { isLiking = false; }
        }

        async function fetchCommentsForVibe(postId) {
            const res = await fetch(apiCall('comments') + `&post_id=${postId}`);
            const data = await res.json(); postCommentsCache[postId] = data.comments; 
        }

        function startFloatingComments(postId) {
            if (floatIntervals[postId]) return;
            floatIntervals[postId] = setInterval(() => {
                const comments = postCommentsCache[postId];
                if (!comments || comments.length === 0) return;
                const area = document.getElementById(`floatArea-${postId}`);
                if (!area) return;
                const c = comments[Math.floor(Math.random() * comments.length)];
                const div = document.createElement('div'); div.className = 'floating-comment'; div.style.left = `${10 + Math.random() * 40}%`; 
                div.innerHTML = `<img src="${getProxyUrl(c.avatar_url || 'https://ui-avatars.com/api/?name='+c.username+'&background=random')}"><div class="fc-text"><span class="fc-name">${c.username}</span><span class="fc-msg">${c.content}</span></div>`;
                area.appendChild(div); setTimeout(() => { if(div.parentNode) div.remove(); }, 5000);
            }, 3000 + Math.random() * 2000); 
        }

        function stopFloatingComments(postId) { clearInterval(floatIntervals[postId]); delete floatIntervals[postId]; }

        function openComments(postId, postSlug = null) {
            currentOpenPostId = postId;
            document.getElementById('commentsList').innerHTML = '<div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            if(postSlug && window.location.pathname !== BASE_PATH + `/post/${postSlug}`) {
                window.history.pushState(null, '', BASE_PATH + `/post/${postSlug}`);
            }
            openModal('commentsModal', 'commentInput');
            fetchCommentsForVibe(postId).then(renderCommentsList);
        }

        function renderCommentNode(c, isReply = false) {
            const imgHtml = c.image_url ? `<div style="margin-top: 0.6rem; border-radius: 12px; overflow: hidden; background: var(--surface); border: 1px solid var(--surface-hover); display: flex; justify-content: center;"><img src="${getProxyUrl(c.image_url)}" style="max-width: 100%; max-height: 350px; object-fit: contain; display: block;"></div>` : '';
            const textHtml = c.content ? `<div class="comment-text mt-1" style="margin-top: 4px;">${linkify(c.content)}</div>` : '';
            const likeIcon = c.is_liked > 0 ? 'ph-fill text-error' : 'ph';
            const likeStyle = c.is_liked > 0 ? 'color: var(--error);' : '';
            const canDelete = currentUser && (c.user_id === currentUser.id || isMyPost(currentOpenPostId));
            
            return `
            <div class="comment-item smooth-fade-in ${isReply ? 'is-reply' : ''}" id="comment-node-${c.id}">
                <img src="${getProxyUrl(c.avatar_url || 'https://ui-avatars.com/api/?name='+c.username+'&background=random')}" onclick="navigate('/profile/${c.user_id}'); closeModal('commentsModal');">
                <div class="comment-content">
                    <div class="comment-header">
                        <div>
                            <span class="comment-author" onclick="navigate('/profile/${c.user_id}'); closeModal('commentsModal');">${c.username}</span> 
                            <span class="comment-time">${timeAgo(c.created_at)}</span>
                        </div>
                        <i class="ph ph-trash comment-delete" style="${!canDelete ? 'display:none;' : ''}" onclick="confirmDeleteComment(${c.id})"></i>
                    </div>
                    ${textHtml}
                    ${imgHtml}
                    <div class="comment-actions">
                        <div class="c-action-btn" onclick="replyToComment(${isReply ? c.parent_id : c.id}, '${c.username}')">
                            <i class="ph ph-arrow-u-down-left"></i> Ответить
                        </div>
                        <div class="c-action-btn" onclick="toggleCommentLike(${c.id}, this)">
                            <i class="${likeIcon} ph-heart" style="${likeStyle}"></i> <span class="like-count-span" style="${likeStyle}">${c.likes_count > 0 ? c.likes_count : '0'}</span>
                        </div>
                    </div>
                </div>
            </div>`;
        }

        function isMyPost(postId) {
            const postCard = document.querySelector(`.post-card[data-id="${postId}"]`);
            if(!postCard) return false;
            return !!postCard.querySelector('.ph-dots-three');
        }

        function renderCommentsList() {
            const list = document.getElementById('commentsList');
            const comments = postCommentsCache[currentOpenPostId] || [];
            
            if(comments.length === 0) { list.innerHTML = '<div class="empty-state smooth-fade-in"><i class="ph ph-chat-teardrop"></i><p>Нет комментариев</p></div>'; return; }
            
            const parents = comments.filter(c => !c.parent_id);
            let html = '';
            
            parents.forEach(p => {
                html += renderCommentNode(p, false);
                const children = comments.filter(c => c.parent_id === p.id);
                children.forEach(child => {
                    html += renderCommentNode(child, true);
                });
            });
            
            list.innerHTML = html;
            setTimeout(() => { list.scrollTo({ top: list.scrollHeight, behavior: 'smooth' }); }, 50);
        }

        async function toggleCommentLike(commentId, btnWrapper) {
            if(!requireAuthClient()) return;
            const icon = btnWrapper.querySelector('i');
            const countSpan = btnWrapper.querySelector('.like-count-span');
            let isLiked = icon.classList.contains('ph-fill');
            let count = parseInt(countSpan.textContent) || 0;
            
            if(isLiked) {
                icon.className = 'ph ph-heart'; icon.style.color = ''; 
                countSpan.style.color = ''; countSpan.textContent = count - 1 || '0';
            } else {
                icon.className = 'ph-fill ph-heart text-error'; icon.style.color = 'var(--error)'; 
                countSpan.style.color = 'var(--error)'; countSpan.textContent = count + 1;
            }
            
            const fd = new FormData(); fd.append('comment_id', commentId); fd.append('csrf_token', csrfToken);
            try { await fetch(apiCall('toggle_comment_like'), { method: 'POST', body: fd }); } catch(e) {}
        }

        function replyToComment(id, username) {
            replyingToCommentId = id;
            document.getElementById('replyingToName').textContent = username;
            document.getElementById('replyingToIndicator').classList.remove('hidden');
            document.getElementById('commentInput').focus();
        }

        function cancelReply() {
            replyingToCommentId = null;
            document.getElementById('replyingToIndicator').classList.add('hidden');
        }

        function confirmDeleteComment(commentId) {
            showConfirm('Удаление', 'Удалить этот комментарий?', () => deleteComment(commentId));
        }

        async function deleteComment(commentId) {
            const fd = new FormData(); fd.append('id', commentId); fd.append('csrf_token', csrfToken);
            const res = await fetch(apiCall('delete_comment'), { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                postCommentsCache[currentOpenPostId] = postCommentsCache[currentOpenPostId].filter(c => c.id !== commentId);
                renderCommentsList();
                const countEl = document.getElementById(`commentCount-${currentOpenPostId}`);
                if(countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent) - 1);
            } else {
                showToast("Нет прав на удаление");
            }
        }

        let pendingCommentImageFile = null;
        function handleCommentImage(e) {
            const file = e.target.files[0];
            if(!file) return;
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой. Максимум 20 МБ.'); e.target.value = ''; return; }
            pendingCommentImageFile = file;
            document.getElementById('commentImagePreview').src = URL.createObjectURL(file);
            document.getElementById('commentImagePreviewContainer').classList.remove('hidden');
        }

        function clearCommentImage() {
            pendingCommentImageFile = null;
            document.getElementById('commentImageUpload').value = '';
            document.getElementById('commentImagePreviewContainer').classList.add('hidden');
        }

        async function sendComment(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            
            const btn = document.getElementById('sendCommentBtn');
            const input = document.getElementById('commentInput');
            const content = input.value.trim();
            
            if(!content && !pendingCommentImageFile) return;
            
            isProcessing = true; 
            btn.disabled = true; 
            input.disabled = true;
            btn.innerHTML = '<i class="ph ph-circle-notch spin"></i>';
            
            try {
                let imageUrl = '';
                if(pendingCommentImageFile) {
                    imageUrl = await uploadToImgBB(pendingCommentImageFile);
                }

                const fd = new FormData(); 
                fd.append('post_id', currentOpenPostId); 
                fd.append('content', content); 
                fd.append('image_url', imageUrl || '');
                fd.append('parent_id', replyingToCommentId || '');
                fd.append('csrf_token', csrfToken);
                
                const res = await fetch(apiCall('add_comment'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if(data.success) {
                    input.value = ''; 
                    input.style.height = 'auto';
                    clearCommentImage();
                    cancelReply();

                    const countEl = document.getElementById(`commentCount-${currentOpenPostId}`);
                    if(countEl) countEl.textContent = parseInt(countEl.textContent) + 1;
                    
                    await fetchCommentsForVibe(currentOpenPostId);
                    renderCommentsList();
                } else {
                    showToast(data.error || 'Ошибка');
                }
            } finally { 
                btn.disabled = false; 
                input.disabled = false;
                btn.innerHTML = '<i class="ph-fill ph-paper-plane-right"></i>';
                isProcessing = false; 
                input.focus(); 
            }
        }

        window.addEventListener('resize', () => {
            initTabIndicator();
            const wrapper = document.getElementById('feedWrapper');
            if(wrapper) wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;
        });

        /* ─── МЕССЕНДЖЕР ───────────────────────────────── */

        let ws = null;
        let wsReconnectTimer = null;
        let wsConnected = false;
        let currentConvId = null;
        window.getCurrentConvId = () => currentConvId;
        let currentConvPartner = null;
        let messengerConversations = [];
        let messengerMessages = {};
        let replyToMessage = null;
        let editMessageId = null;
        let typingTimeout = null;
        let isTyping = false;
        let msgBadgeCount = 0;
        let messengerInitialized = false;
        let activeConvUsers = {};
        let pendingNewChatTimeout = null;


        async function connectWebSocket() {
            if (ws && ws.readyState === 1) return;
            if (!currentUser) return;

            let token;
            try {
                token = await getSessionToken();
            } catch (e) {
                scheduleWsReconnect();
                return;
            }
            if (!token) {
                scheduleWsReconnect();
                return;
            }

            try {
                ws = new WebSocket(WS_URL);
            } catch (e) {
                scheduleWsReconnect();
                return;
            }

            ws.onopen = () => {
                wsConnected = true;
                ws.send(JSON.stringify({ type: 'auth', token }));
            };

            ws.onmessage = async (event) => {
                let data;
                try { data = JSON.parse(event.data); } catch { return; }
                handleWsMessage(data);
            };

            ws.onclose = () => {
                wsConnected = false;
                if (currentUser) scheduleWsReconnect();
            };

            ws.onerror = () => {};
        }

        function scheduleWsReconnect() {
            if (wsReconnectTimer) clearTimeout(wsReconnectTimer);
            wsReconnectTimer = setTimeout(() => {
                if (currentUser) connectWebSocket();
            }, 5000);
        }

        async function getSessionToken() {
            if (wsToken && Date.now() < wsTokenExpires - 5000) {
                return wsToken;
            }
            if (wsTokenPromise) {
                return wsTokenPromise;
            }
            if (!csrfToken) {
                throw new Error('No CSRF token');
            }
            wsTokenPromise = fetch(apiCall('ws_token'), {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.ws_token) {
                    wsToken = data.ws_token;
                    wsTokenExpires = Date.now() + 30000;
                    return wsToken;
                }
                throw new Error('WS token request failed');
            })
            .finally(() => {
                wsTokenPromise = null;
            });
            return wsTokenPromise;
        }

        let pendingConvOpen = null;

        async function handleWsMessage(data) {
            switch (data.type) {
                case 'auth_ok': {
                    wsConnected = true;
                    if (messengerInitialized && pendingConvOpen) {
                        const conv = messengerConversations.find(c => c.id === pendingConvOpen);
                        if (conv) {
                            openChat(conv);
                            pendingConvOpen = null;
                        }
                    }
                    if (currentConvId) markAsRead(currentConvId);
                    break;
                }

                case 'conversations': {
                    const serverConvs = data.conversations || [];

                    messengerConversations = sortConversationsByLastMsg(serverConvs.map(sc => {
                        return {
                            ...sc,
                            unread_count: parseInt(sc.unread_count) || 0,
                        };
                    }));

                    if (pendingConvOpen) {
                        const conv = messengerConversations.find(c => c.id === pendingConvOpen);
                        if (conv) {
                            openChat(conv);
                            pendingConvOpen = null;
                        }
                    }

                    const unread = messengerConversations.reduce((s, c) => s + (parseInt(c.unread_count) || 0), 0);
                    updateMsgBadge(unread);
                    renderConvList();
                    break;
                }

                case 'new_message': {
                    const msg = data.message;
                    let idx = messengerConversations.findIndex(c => c.id == msg.conversation_id);

                    if (idx === -1 && msg.sender_id != currentUser.id) {
                        const newConv = {
                            id: msg.conversation_id,
                            participants: [{ id: msg.sender_id, username: msg.username, avatar_url: msg.avatar_url }],
                            last_message: msg.content || '',
                            last_message_at: msg.created_at,
                            last_sender_id: msg.sender_id,
                            unread_count: 1,
                        };
                        messengerConversations.unshift(newConv);
                        renderConvList();
                        updateMsgBadge(messengerConversations.reduce((s, c) => s + (parseInt(c.unread_count) || 0), 0));
                    } else if (idx !== -1) {
                        const conv = messengerConversations[idx];
                        conv.last_message = msg.content || '';
                        conv.last_message_at = msg.created_at;
                        conv.last_sender_id = msg.sender_id;
                        if (currentConvId != msg.conversation_id) {
                            conv.unread_count = (parseInt(conv.unread_count) || 0) + 1;
                        }
                        messengerConversations.splice(idx, 1);
                        messengerConversations.unshift(conv);
                        renderConvList();
                        const totalUnread = messengerConversations.reduce((s, c) => s + (parseInt(c.unread_count) || 0), 0);
                        updateMsgBadge(totalUnread);
                    }

                    if (currentConvId == msg.conversation_id) {
                        if (!messengerMessages[currentConvId]) messengerMessages[currentConvId] = [];
                        const existingIdx = messengerMessages[currentConvId].findIndex(m => m.id === msg.id);
                        if (existingIdx === -1) {
                            if (msg.sender_id == currentUser.id) {
                                const tempIdx = messengerMessages[currentConvId].findIndex(m => m.id < 0 && m.content === msg.content);
                                if (tempIdx !== -1) messengerMessages[currentConvId].splice(tempIdx, 1);
                            }
                            messengerMessages[currentConvId].push(msg);
                            renderMessages();
                        }
                        if (msg.sender_id != currentUser.id) {
                            markAsRead(currentConvId);
                        }
                    } else if (msg.sender_id != currentUser.id) {
                        const senderName = msg.username || 'Пользователь';
                        let preview = msg.content || '';
                        const conv = messengerConversations.find(c => c.id == msg.conversation_id);
                        if (!conv || !conv.muted) {
                            const avatar = getProxyUrl(msg.avatar_url || `https://ui-avatars.com/api/?name=${senderName}&background=random`);
                            const toastHtml = `<div style="display:flex;align-items:center;gap:8px;text-align:left;"><img src="${avatar}" style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;" onerror="this.style.display='none'"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(preview.substring(0, 40))}</span></div>`;
                            showToast(toastHtml, true);
                            playNotifSound();
                        }
                    }
                    break;
                }

                case 'messages': {
                    if (data.conversation_id) {
                        const page = data.messages || [];
                        if (!data.before) {
                            messengerMessages[data.conversation_id] = page;
                        } else {
                            const existing = messengerMessages[data.conversation_id] || [];
                            const older = page.filter(message => !existing.some(item => item.id == message.id));
                            messengerMessages[data.conversation_id] = [...older, ...existing];
                        }
                        messageHistoryHasMore[data.conversation_id] = page.length >= MESSAGE_PAGE_SIZE;
                        if (currentConvId == data.conversation_id) {
                            updateChatHistoryControls();
                            renderMessages(!!data.before);
                        }
                    }
                    if (data.before && historyLoadConversationId == data.conversation_id) {
                        clearTimeout(historyLoadTimer);
                        historyLoadTimer = null;
                        historyLoadConversationId = null;
                        isLoadingMoreMessages = false;
                    }
                    updateChatHistoryControls();
                    break;
                }

                case 'typing': {
                    if (currentConvId == data.conversation_id && data.user_id != currentUser.id) {
                        const el = document.getElementById('typingIndicator');
                        const text = document.getElementById('typingText');
                        if (data.is_typing) {
                            el.classList.remove('hidden');
                            if (text) text.textContent = (data.username || 'Пользователь') + ' печатает...';
                            clearTimeout(el._typingTimer);
                            el._typingTimer = setTimeout(() => {
                                el.classList.add('hidden');
                            }, 10000);
                            const sc = document.getElementById('chatMessages');
                            if (isChatNearBottom(sc)) {
                                sc.scrollTop = sc.scrollHeight;
                            }
                        } else {
                            clearTimeout(el._typingTimer);
                            const sc = document.getElementById('chatMessages');
                            const nearBot = isChatNearBottom(sc);
                            el.classList.add('hidden');
                            if (sc && nearBot) {
                                sc.scrollTop = sc.scrollHeight;
                            }
                        }
                    }
                    break;
                }

                case 'status_update': {
                    if (messengerMessages[currentConvId]) {
                        const msg = messengerMessages[currentConvId].find(m => m.id == data.message_id);
                        if (msg && msg.sender_id == currentUser.id) {
                            msg.my_status = data.status;
                            renderMessages(true);
                        }
                    }
                    break;
                }

                case 'read_receipt': {
                    if (currentConvId == data.conversation_id) {
                        const msgs = messengerMessages[currentConvId] || [];
                        msgs.forEach(m => {
                            if (m.sender_id == currentUser.id && m.my_status !== 'read') {
                                m.my_status = 'read';
                            }
                        });
                        renderMessages(true);
                    }
                    break;
                }

                case 'message_deleted': {
                    if (currentConvId == data.conversation_id) {
                        const msgs = messengerMessages[currentConvId] || [];
                        const idx = msgs.findIndex(m => m.id == data.message_id);
                        if (idx !== -1) {
                            msgs.splice(idx, 1);
                            renderMessages(true);
                        }
                    }
                    break;
                }

                case 'message_edited': {
                    if (currentConvId == data.conversation_id) {
                        const msgs = messengerMessages[currentConvId] || [];
                        const msg = msgs.find(m => m.id == data.message_id);
                        if (msg) {
                            msg.content = data.content;
                            renderMessages(true);
                        }
                    }
                    break;
                }

                case 'conversation_created': {
                    messengerMessages[data.conversation_id] = data.messages || [];
                    const partner = data.partner || null;

                    let conv = messengerConversations.find(c => c.id === data.conversation_id);
                    if (!conv) {
                        conv = {
                            id: data.conversation_id,
                            participants: partner ? [{ id: partner.id, username: partner.username, avatar_url: partner.avatar_url }] : [],
                            last_message: '',
                            last_message_at: null,
                            unread_count: 0,
                        };
                        messengerConversations.unshift(conv);
                        renderConvList();
                    }

                    const wasPending = currentConvId === 'pending_' + partner?.id;
                    if (currentConvId == data.conversation_id || wasPending) {
                        clearPendingNewChatTimeout();
                        currentConvId = data.conversation_id;
                        openChat(conv);
                        navigate('/messages/' + data.conversation_id, true);
                    }
                    break;
                }

                case 'conversation_left': {
                    messengerConversations = messengerConversations.filter(c => c.id != data.conversation_id);
                    if (currentConvId == data.conversation_id) {
                        showConvList();
                    }
                    renderConvList();
                    break;
                }

                case 'conversation_cleared': {
                    if (data.conversation_id) {
                        messengerMessages[data.conversation_id] = [];
                        if (currentConvId == data.conversation_id) {
                            renderMessages();
                        }
                    }
                    break;
                }

                case 'user_blocked': {
                    _blockedUserIds.add(Number(data.user_id));
                    if (currentConvPartner && currentConvPartner.id == data.user_id) {
                        openChat(messengerConversations.find(c => c.id == currentConvId) || { id: currentConvId, participants: [currentConvPartner] });
                    }
                    renderConvList();
                    showToast('Пользователь заблокирован');
                    break;
                }

                case 'user_unblocked': {
                    _blockedUserIds.delete(Number(data.user_id));
                    if (currentConvPartner && currentConvPartner.id == data.user_id) {
                        openChat(messengerConversations.find(c => c.id == currentConvId) || { id: currentConvId, participants: [currentConvPartner] });
                    }
                    renderConvList();
                    break;
                }

                case 'require_captcha': {
                    captchaRequired = true;
                    if (_pendingWsContent) {
                        const input = document.getElementById('chatInput');
                        if (input) input.value = _pendingWsContent;
                        _pendingSpamMessage = () => {
                            const form = document.getElementById('chatForm');
                            if (form) form.dispatchEvent(new Event('submit', { cancelable: true }));
                        };
                        _pendingWsContent = null;
                        _pendingWsReply = null;
                    } else {
                        _pendingSpamMessage = null;
                    }
                    showSpamCaptcha();
                    break;
                }

                case 'error': {
                    if (data.error === 'Invalid session') {
                        wsToken = null;
                        wsTokenExpires = 0;
                        scheduleWsReconnect();
                    } else {
                        showToast(data.error || 'Ошибка');
                    }
                    if (currentConvId && String(currentConvId).startsWith('pending_')) {
                        showConvList();
                        clearPendingNewChatTimeout();
                    }
                    break;
                }
            }
        }

        function updateConvLastMsg(msg) {
            const conv = messengerConversations.find(c => c.id == msg.conversation_id);
            if (conv) {
                conv.last_message = msg.content || '';
                conv.last_message_at = msg.created_at;
                conv.last_sender_id = msg.sender_id;
            }
        }

        function updateMsgBadge(count) {
            msgBadgeCount = count;
            ['msgBadgeBottom', 'msgBadgeTop'].forEach(id => {
                const badge = document.getElementById(id);
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                    }
                }
            });
        }

        function sortConversationsByLastMsg(convs) {
            return convs.slice().sort((a, b) => {
                const ta = a.last_message_at ? new Date(a.last_message_at).getTime() : 0;
                const tb = b.last_message_at ? new Date(b.last_message_at).getTime() : 0;
                if (tb !== ta) return tb - ta;
                return (b.id || 0) - (a.id || 0);
            });
        }

        async function openMessenger(openConvId = null) {
            if (!currentUser) return;
            messengerInitialized = true;

            const hasImmediateConv = openConvId && messengerConversations.find(c => c.id === openConvId);

            if (hasImmediateConv) {
                document.getElementById('convListSection').classList.add('hidden');
                document.getElementById('chatSection').classList.remove('hidden');
            } else {
                document.getElementById('convListSection').classList.remove('hidden');
                document.getElementById('chatSection').classList.add('hidden');
            }

            connectWebSocket();

            if (openConvId) {
                const conv = messengerConversations.find(c => c.id === openConvId);
                if (conv) {
                    openChat(conv);
                } else {
                    pendingConvOpen = openConvId;
                }
            }

            if (wsConnected) {
                ws.send(JSON.stringify({ type: 'get_conversations' }));
            } else {
                loadConversationsHttp();
            }
            startConvPolling();
        }

        async function loadConversationsHttp() {
            try {
                const res = await fetch(apiCall('conversations'));
                const data = await res.json();
                if (data.success) {
                    messengerConversations = sortConversationsByLastMsg(data.conversations || []);
                    const unread = messengerConversations.reduce((s, c) => s + (parseInt(c.unread_count) || 0), 0);
                    updateMsgBadge(unread);
                    renderConvList();
                }
            } catch (e) {}
        }

        let convPollInterval = null;

        function startConvPolling() {
            stopConvPolling();
            convPollInterval = setInterval(() => {
                if (currentUser && messengerInitialized) {
                    loadConversationsHttp();
                }
            }, 5000);
        }

        function stopConvPolling() {
            if (convPollInterval) { clearInterval(convPollInterval); convPollInterval = null; }
        }

        function showConvList() {
            clearPendingNewChatTimeout();
            releaseChatBottomPin();
            const convSection = document.getElementById('convListSection');
            const chatSection = document.getElementById('chatSection');
            if (convSection) convSection.classList.remove('hidden');
            if (chatSection) chatSection.classList.add('hidden');
            currentConvId = null;
            currentConvPartner = null;
            navigate('/messages', true);
        }

        window.showConvList = showConvList;

        function clearPendingNewChatTimeout() {
            if (pendingNewChatTimeout) {
                clearTimeout(pendingNewChatTimeout);
                pendingNewChatTimeout = null;
            }
        }

        function renderConvList() {
            const list = document.getElementById('convListItems');
            messengerConversations = sortConversationsByLastMsg(messengerConversations);
            if (!messengerConversations.length) {
                list.innerHTML = `<div class="empty-state"><i class="ph ph-chat-circle"></i><p>Нет диалогов</p></div>`;
                return;
            }

            list.innerHTML = messengerConversations.map(conv => {
                const partner = conv.participants && conv.participants[0];
                if (!partner) return '';
                const blocked = isUserBlocked(partner.id);
                const muted = !!conv.muted;
                const avatar = getProxyUrl(partner.avatar_url || `https://ui-avatars.com/api/?name=${partner.username}&background=random`);
                const lastMsg = conv.last_message || '';
                const isMe = conv.last_sender_id == currentUser.id;
                const unread = parseInt(conv.unread_count) || 0;
                const time = conv.last_message_at ? timeAgo(conv.last_message_at) : '';

                const preview = getConversationPreview(lastMsg);
                const blockStyle = blocked ? 'style="filter:grayscale(100%);opacity:0.5;"' : '';
                const nameStyle = blocked ? 'style="color:var(--text-muted);"' : '';
                return `<div class="conv-item ${blocked ? 'conv-blocked' : ''}" onclick="openConv(${conv.id})" oncontextmenu="event.preventDefault(); showConvContextMenu(${conv.id}, event)">
                    <div style="position:relative;flex-shrink:0;">
                        <img src="${avatar}" class="conv-avatar" loading="lazy" ${blockStyle}>
                    </div>
                    <div class="conv-info">
                        <div class="conv-top">
                            <span class="conv-name" ${nameStyle}>${partner.username}${blocked ? ' (заблок.)' : ''}</span>
                            <span class="conv-time">${muted ? '<i class="ph ph-bell-slash" style="font-size:0.7rem;margin-right:2px;"></i>' : ''}${time}</span>
                        </div>
                        <div class="conv-bottom">
                            <span class="conv-last-msg">${isMe ? 'Вы: ' : ''}${escHtml(preview.substring(0, 60))}</span>
                            ${unread > 0 ? `<span class="conv-unread">${unread > 99 ? '99+' : unread}</span>` : ''}
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        function getConversationPreview(content) {
            const text = String(content || '').trim();
            const urls = text.match(/https?:\/\/[^\s<>"']+/g) || [];
            const imageHosts = ['ibb.co', 'i.ibb.co', 'imgbb.com', 'i.imgbb.com'];
            const hasImage = urls.some(url => {
                if (/\.(gif|png|jpe?g|webp)(?:[?#]|$)/i.test(url) || /opengifs\.webounty\.ru\/g\//i.test(url)) return true;
                try { return imageHosts.includes(new URL(url).hostname.toLowerCase()); } catch (e) { return false; }
            });
            if (hasImage) return 'Картинка';

            const fileMatch = text.match(/\[dumpfile:[^:\]]+:([^:]*):\d+:([^\]]*)\]/i);
            if (fileMatch) {
                let name = fileMatch[1] || '';
                let mime = fileMatch[2] || '';
                try { name = decodeURIComponent(name); } catch (e) {}
                try { mime = decodeURIComponent(mime); } catch (e) {}
                const ext = name.split('.').pop().toLowerCase();
                if (mime.startsWith('video/') || ['mp4','mov','avi','mkv','webm'].includes(ext)) return 'Видео';
                if (mime.startsWith('audio/') || ['mp3','wav','ogg','m4a','flac','aac','wma'].includes(ext)) return 'Аудио';
                return 'Файл';
            }

            return text;
        }

        window.openConv = async function(convId) {
            const conv = messengerConversations.find(c => c.id === convId);
            if (conv) {
                openChat(conv);
                navigate('/messages/' + convId, true);
            } else {
                showToast('Диалог не найден');
            }
        };

        async function openChat(conv) {
            currentConvId = conv.id;
            currentConvPartner = conv.participants && conv.participants[0];

            if (!currentConvPartner) {
                showToast('Ошибка загрузки чата');
                return;
            }

            const avatar = getProxyUrl(currentConvPartner.avatar_url || `https://ui-avatars.com/api/?name=${currentConvPartner.username}&background=random`);
            const isBlocked = isUserBlocked(currentConvPartner.id);

            document.getElementById('convListSection').classList.add('hidden');
            document.getElementById('chatSection').classList.remove('hidden');
            document.getElementById('chatPartnerAvatar').src = avatar;
            document.getElementById('chatPartnerAvatar').style.filter = isBlocked ? 'grayscale(100%)' : '';
            document.getElementById('chatPartnerName').textContent = isBlocked ? currentConvPartner.username + ' (заблокирован)' : currentConvPartner.username;
            document.getElementById('chatPartnerName').style.color = isBlocked ? 'var(--text-muted)' : '';
            document.getElementById('typingIndicator').classList.add('hidden');

            const chatForm = document.getElementById('chatForm');
            const blockedBanner = document.getElementById('chatBlockedBanner');
            const chatMessages = document.getElementById('chatMessages');
            const chatMessagesInner = document.getElementById('chatMessagesInner');
            if (chatMessagesInner) chatMessagesInner.innerHTML = '';
            if (chatMessages) {
                chatMessages.scrollTop = 0;
                chatMessages.onscroll = onChatScroll;
                chatMessages.onwheel = releaseChatBottomPin;
                chatMessages.ontouchstart = releaseChatBottomPin;
                chatMessages.onpointerdown = releaseChatBottomPin;
            }
            clearTimeout(historyLoadTimer);
            historyLoadTimer = null;
            historyLoadConversationId = null;
            isLoadingMoreMessages = false;
            messageHistoryHasMore[conv.id] = true;
            document.getElementById('chatLoadMore').classList.add('hidden');
            if (isBlocked) {
                chatForm.style.display = 'none';
                blockedBanner.classList.remove('hidden');
            } else {
                chatForm.style.display = '';
                blockedBanner.classList.add('hidden');
            }

            if (wsConnected) {
                ws.send(JSON.stringify({ type: 'get_messages', conversation_id: conv.id }));
            } else {
                loadMessagesHttp(conv.id);
            }
            markAsRead(conv.id);

            conv.unread_count = 0;
            renderConvList();
            const totalUnread = messengerConversations.reduce((s, c) => s + (parseInt(c.unread_count) || 0), 0);
            updateMsgBadge(totalUnread);
            document.getElementById('chatInput').focus();
        }

        async function loadMessagesHttp(convId) {
            try {
                const res = await fetch(apiCall('messages') + '&conversation_id=' + convId);
                const data = await res.json();
                if (data.success && data.messages) {
                    messengerMessages[convId] = data.messages;
                    messageHistoryHasMore[convId] = data.messages.length >= MESSAGE_PAGE_SIZE;
                    if (currentConvId === convId) {
                        updateChatHistoryControls();
                        renderMessages();
                    }
                }
            } catch (e) {
                console.error('loadMessagesHttp error:', e);
            }
        }

        function renderMsgBody(text) {
            if (!text) return '';

            const dfRegex = /\[dumpfile:([^:\]]+):([^:]*):(\d+):([^\]]*)\]/g;
            const dfReplacements = [];
            const DF_MARKER = '\uE000';
            let processed = text.replace(dfRegex, (match, key, name, size, type) => {
                const idx = dfReplacements.length;
                dfReplacements.push({ key, name, size, type });
                return DF_MARKER + 'DF' + idx + DF_MARKER;
            });

            const urlRegex = /(https?:\/\/[^\s<>"']+)/g;
            const parts = [];
            let lastIdx = 0;
            let match;
            while ((match = urlRegex.exec(processed)) !== null) {
                if (match.index > lastIdx) {
                    parts.push({ type: 'text', content: processed.substring(lastIdx, match.index) });
                }
                const url = match[0];
                const isImage = /\.(gif|png|jpg|jpeg|webp)(\?.*)?$/i.test(url) || url.includes('i.ibb.co') || url.includes('ibb.co') || /opengifs\.webounty\.ru\/g\//.test(url);
                parts.push({ type: isImage ? 'image' : 'link', content: url });
                lastIdx = match.index + match[0].length;
            }
            if (lastIdx < processed.length) {
                parts.push({ type: 'text', content: processed.substring(lastIdx) });
            }
            if (parts.length === 0) parts.push({ type: 'text', content: processed });

            let html = parts.map(p => {
                if (p.type === 'text') return linkify(escHtml(p.content));
                if (p.type === 'image') {
                    const proxyUrl = getChatImageProxyUrl(p.content);
                    const encodedUrl = encodeURIComponent(p.content);
                    return `<div class="msg-image-wrapper"><img src="${proxyUrl}" data-image-url="${encodedUrl}" class="msg-image" loading="lazy" onclick="event.stopPropagation(); viewChatImage(decodeURIComponent(this.dataset.imageUrl))" onload="chatImageLoaded(this)" onerror="retryChatImage(this)" oncontextmenu="event.stopPropagation(); event.preventDefault()"><button type="button" class="msg-image-retry" onclick="event.stopPropagation(); retryChatImage(this.previousElementSibling, true)"><i class="ph ph-arrow-clockwise"></i><span>Повторить</span></button></div>`;
                }
                const h = p.content.replace(/&quot;/g, '%22').replace(/</g, '%3C').replace(/>/g, '%3E').replace(/&amp;/g, '&');
                return '<a href="' + h + '" target="_blank" rel="noopener noreferrer" class="msg-link" onclick="event.stopPropagation()">' + p.content + '</a>';
            }).join('');

            const dfRestoreRegex = new RegExp(DF_MARKER + 'DF(\\d+)' + DF_MARKER + '\\n?', 'g');
            html = html.replace(dfRestoreRegex, (m, idx) => {
                const d = dfReplacements[parseInt(idx)];
                if (!d) return '';
                const decodedName = decodeURIComponent(d.name || 'file');
                const ext = (decodedName || '').split('.').pop().toLowerCase();
                const mimeType = decodeURIComponent(d.type || '');
                const isVideo = mimeType.startsWith('video/') || ['mp4','mov','avi','mkv','webm'].includes(ext);
                const isAudio = mimeType.startsWith('audio/') || ['mp3','wav','ogg','m4a','flac','aac','wma'].includes(ext);
                const fileSize = parseInt(d.size, 10) || 0;
                const downloadUrl = BASE_PATH + '/index.php?api=file_download&key=' + encodeURIComponent(d.key);
                const inlineUrl = BASE_PATH + '/index.php?api=file_download&inline=1&key=' + encodeURIComponent(d.key);
                const safeFileNameAttr = encodeURIComponent(decodedName).replace(/'/g, "%27");

                if (isVideo) {
                    const playerId = 'dumpplayer_' + Math.random().toString(36).slice(2, 10);
                    return `<div class="dump-player dump-player-video" data-player-id="${playerId}" data-src="${inlineUrl}" data-download="${downloadUrl}" data-filename="${safeFileNameAttr}">
                        <video id="${playerId}" preload="metadata" playsinline></video>
                        <div class="dump-player-overlay"><button class="dump-player-big-play" aria-label="Play"><i class="ph-fill ph-play"></i></button></div>
                        <div class="dump-player-controls">
                            <div class="dump-player-progress"><div class="dump-player-progress-track"></div><div class="dump-player-progress-fill"></div><div class="dump-player-progress-handle"></div></div>
                            <div class="dump-player-row">
                                <button class="dump-player-btn dump-player-play" aria-label="Play/Pause"><i class="ph-fill ph-play"></i></button>
                                <div class="dump-player-times"><span class="dump-player-time">0:00</span><span>/</span><span class="dump-player-duration">0:00</span></div>
                                <div class="dump-player-actions">
                                    <div class="dump-player-volume-wrap">
                                        <button class="dump-player-btn dump-player-mute" aria-label="Mute"><i class="ph-fill ph-speaker-high"></i></button>
                                        <div class="dump-player-volume"><div class="dump-player-volume-fill"></div></div>
                                    </div>
                                    <button class="dump-player-btn dump-player-download" aria-label="Download" onclick="event.stopPropagation(); downloadDumpMedia(this)"><i class="ph ph-download-simple"></i></button>
                                    <button class="dump-player-btn dump-player-fullscreen" aria-label="Fullscreen"><i class="ph ph-corners-out"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="dump-player-loader"><div class="spinner"></div></div>
                    </div>`;
                }

                if (isAudio) {
                    const playerId = 'dumpplayer_' + Math.random().toString(36).slice(2, 10);
                    return `<div class="msg-audio-widget" data-player-id="${playerId}" data-src="${inlineUrl}" data-download="${downloadUrl}" data-filename="${safeFileNameAttr}">
                        <audio id="${playerId}" preload="metadata"></audio>
                        <button class="msg-audio-play" aria-label="Play/Pause"><i class="ph-fill ph-play"></i></button>
                        <div class="msg-audio-col">
                            <span class="msg-audio-title">${escHtml(decodedName)}</span>
                            <div class="msg-audio-row">
                                <div class="msg-audio-progress"><div class="msg-audio-progress-track"></div><div class="msg-audio-progress-fill"></div><div class="msg-audio-progress-handle"></div></div>
                                <span class="msg-audio-time">0:00</span>
                            </div>
                        </div>
                        <a href="${downloadUrl}" class="msg-audio-download" onclick="event.stopPropagation();" download><i class="ph ph-download-simple"></i></a>
                    </div>`;
                }

                const icon = getFileIcon(ext);
                const fileLabel = mimeType.startsWith('application/pdf') || ext === 'pdf' ? 'PDF'
                    : ['zip', 'rar', '7z', 'tar', 'gz'].includes(ext) ? 'Архив'
                    : ['doc', 'docx', 'odt'].includes(ext) ? 'Документ'
                    : ['xls', 'xlsx', 'ods', 'csv'].includes(ext) ? 'Таблица'
                    : ['ppt', 'pptx', 'odp'].includes(ext) ? 'Презентация'
                    : ext ? ext.toUpperCase() : 'ФАЙЛ';
                return `<div class="msg-file-widget" onclick="event.stopPropagation();">
                    <div class="msg-file-icon">${icon}</div>
                    <div class="msg-file-info">
                        <span class="msg-file-name">${escHtml(decodedName)}</span>
                        <span class="msg-file-meta"><span class="msg-file-kind">${fileLabel}</span><span class="msg-file-separator">•</span>${formatFileSize(fileSize)}</span>
                    </div>
                    <a href="${downloadUrl}" class="msg-file-dl" onclick="event.stopPropagation();" download title="Скачать"><i class="ph ph-download-simple"></i></a>
                </div>`;
            });

            return html;
        }

        function getChatImageProxyUrl(url, retry = 0) {
            const proxyUrl = getProxyUrl(url);
            const separator = proxyUrl.includes('?') ? '&' : '?';
            return proxyUrl + separator + 'retryable=1' + (retry ? '&retry=' + retry : '');
        }

        function chatImageLoaded(img) {
            img.style.display = '';
            img.dataset.retryCount = '0';
            delete img.dataset.directFallback;
            const wrapper = img.closest('.msg-image-wrapper');
            if (wrapper) wrapper.classList.remove('loading', 'failed');
            keepChatAtBottomAfterMedia(img);
        }

        function retryChatImage(img, manual = false) {
            if (!img) return;
            const wrapper = img.closest('.msg-image-wrapper');
            let originalUrl = '';
            try { originalUrl = decodeURIComponent(img.dataset.imageUrl || ''); } catch (e) {}
            if (!originalUrl) return;

            if (manual) {
                img.dataset.retryCount = '0';
                delete img.dataset.directFallback;
                img.style.display = '';
            }
            if (wrapper) wrapper.classList.remove('failed');

            const retryCount = parseInt(img.dataset.retryCount || '0', 10);
            if (retryCount < 2) {
                const nextRetry = retryCount + 1;
                img.dataset.retryCount = String(nextRetry);
                if (wrapper) wrapper.classList.add('loading');
                setTimeout(() => {
                    if (img.isConnected) img.src = getChatImageProxyUrl(originalUrl, nextRetry);
                }, manual ? 0 : nextRetry * 300);
                return;
            }

            if (!img.dataset.directFallback) {
                img.dataset.directFallback = '1';
                if (wrapper) wrapper.classList.add('loading');
                setTimeout(() => {
                    if (img.isConnected) img.src = originalUrl;
                }, 300);
                return;
            }

            img.style.display = 'none';
            if (wrapper) wrapper.classList.remove('loading');
            if (wrapper) wrapper.classList.add('failed');
        }
        window.chatImageLoaded = chatImageLoaded;
        window.retryChatImage = retryChatImage;

        function viewChatImage(url) {
            const existing = document.getElementById('imgViewer');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'imgViewer';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0);display:flex;align-items:center;justify-content:center;transition:background 0.25s;';
            overlay.innerHTML = `<img src="${getProxyUrl(url)}" id="imgViewerImg" style="max-width:90vw;max-height:90vh;border-radius:12px;opacity:0;transform:scale(0.92);transition:opacity 0.2s,transform 0.2s;cursor:grab;user-select:none;" draggable="false" oncontextmenu="event.preventDefault()">`;

            overlay.onclick = (e) => { if (e.target === overlay) closeImageViewer(); };
            document.addEventListener('keydown', onImgViewerKey);
            document.body.appendChild(overlay);

            requestAnimationFrame(() => {
                overlay.style.background = 'rgba(0,0,0,0.92)';
                const img = document.getElementById('imgViewerImg');
                img.style.opacity = '1';
                img.style.transform = 'scale(1)';
            });

            let scale = 1, panX = 0, panY = 0, panning = false, lastX = 0, lastY = 0;

            const img = document.getElementById('imgViewerImg');
            img.onwheel = (e) => {
                e.preventDefault();
                const delta = e.deltaY > 0 ? 0.85 : 1.15;
                const newScale = Math.min(5, Math.max(0.5, scale * delta));
                if (newScale !== scale) {
                    scale = newScale;
                    img.style.transform = `scale(${scale}) translate(${panX}px,${panY}px)`;
                    img.style.cursor = scale > 1.01 ? (panning ? 'grabbing' : 'grab') : 'grab';
                }
            };
            img.onmousedown = (e) => {
                if (scale <= 1.01) return;
                e.preventDefault();
                panning = true;
                lastX = e.clientX; lastY = e.clientY;
                img.style.cursor = 'grabbing';
            };
            window.addEventListener('mousemove', (e) => {
                if (!panning) return;
                panX += (e.clientX - lastX) / scale;
                panY += (e.clientY - lastY) / scale;
                lastX = e.clientX; lastY = e.clientY;
                img.style.transform = `scale(${scale}) translate(${panX}px,${panY}px)`;
            });
            window.addEventListener('mouseup', () => {
                panning = false;
                if (img) img.style.cursor = scale > 1.01 ? 'grab' : 'grab';
            });
            img.ondblclick = (e) => {
                e.preventDefault();
                if (scale > 1.01) { scale = 1; panX = 0; panY = 0; img.style.transform = 'scale(1)'; img.style.cursor = 'grab'; }
                else { scale = 2; img.style.transform = `scale(2)`; img.style.cursor = 'grab'; }
            };
        }

        function closeImageViewer() {
            const overlay = document.getElementById('imgViewer');
            if (!overlay) return;
            overlay.style.background = 'rgba(0,0,0,0)';
            const img = document.getElementById('imgViewerImg');
            if (img) { img.style.opacity = '0'; img.style.transform = 'scale(0.92)'; }
            document.removeEventListener('keydown', onImgViewerKey);
            setTimeout(() => overlay.remove(), 250);
        }

        function onImgViewerKey(e) {
            if (e.key === 'Escape') closeImageViewer();
        }

        window.viewChatImage = viewChatImage;

        function formatPlayerTime(seconds) {
            if (!isFinite(seconds) || isNaN(seconds)) return '0:00';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = Math.floor(seconds % 60);
            if (h > 0) return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
            return `${m}:${String(s).padStart(2,'0')}`;
        }

        function downloadDumpMedia(btn) {
            const player = btn.closest('.dump-player, .msg-audio-widget');
            if (!player) return;
            const url = player.dataset.download;
            let filename = player.dataset.filename || 'download';
            try { filename = decodeURIComponent(filename); } catch(e) {}
            if (!url) return;
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            setTimeout(() => a.remove(), 100);
        }
        window.downloadDumpMedia = downloadDumpMedia;

        let __dumpDragState = { target: null, type: null };
        const handleDumpDragMove = (clientX) => {
            if (!__dumpDragState.target) return;
            const player = __dumpDragState.target.closest('.dump-player, .msg-audio-widget');
            const media = player?.querySelector('video, audio');
            if (!media) return;
            const rect = __dumpDragState.target.getBoundingClientRect();
            const x = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            if (__dumpDragState.type === 'progress' && media.duration) {
                media.currentTime = x * media.duration;
                const fill = player.querySelector('.dump-player-progress-fill, .msg-audio-progress-fill');
                const handle = player.querySelector('.dump-player-progress-handle');
                const time = player.querySelector('.dump-player-time, .msg-audio-time');
                if (fill) fill.style.width = (x * 100) + '%';
                if (handle) handle.style.left = (x * 100) + '%';
                if (time) time.textContent = formatPlayerTime(media.currentTime);
            }
            if (__dumpDragState.type === 'volume') {
                media.volume = x;
                media.muted = x === 0;
                const fill = player.querySelector('.dump-player-volume-fill');
                if (fill) fill.style.width = (x * 100) + '%';
            }
        };
        window.addEventListener('mousemove', (e) => handleDumpDragMove(e.clientX));
        window.addEventListener('touchmove', (e) => { if (__dumpDragState.target) handleDumpDragMove(e.touches[0].clientX); }, {passive: true});
        window.addEventListener('mouseup', () => { __dumpDragState = { target: null, type: null }; });
        window.addEventListener('touchend', () => { __dumpDragState = { target: null, type: null }; }, {passive: true});
        document.addEventListener('click', (e) => {
            if (e.target.closest('.dump-player, .msg-audio-widget')) return;
            document.querySelectorAll('.dump-player-volume-wrap.volume-open').forEach(el => el.classList.remove('volume-open'));
        });

        function initDumpPlayers(container) {
            const root = container || document.getElementById('chatMessagesInner') || document.body;

            root.querySelectorAll('.msg-audio-widget:not([data-dump-initialized])').forEach(player => {
                player.setAttribute('data-dump-initialized', '1');
                const media = player.querySelector('audio');
                if (!media) return;
                media.src = player.dataset.src;
                media.load();

                const playBtn = player.querySelector('.msg-audio-play');
                const progressWrap = player.querySelector('.msg-audio-progress');
                const progressFill = player.querySelector('.msg-audio-progress-fill');
                const progressHandle = player.querySelector('.msg-audio-progress-handle');
                const timeEl = player.querySelector('.msg-audio-time');

                const updatePlayIcon = () => {
                    if (playBtn) playBtn.innerHTML = media.paused ? '<i class="ph-fill ph-play"></i>' : '<i class="ph-fill ph-pause"></i>';
                    player.classList.toggle('playing', !media.paused);
                };

                const updateTime = () => {
                    if (timeEl) timeEl.textContent = formatPlayerTime(media.currentTime);
                    const pct = media.duration ? (media.currentTime / media.duration) * 100 : 0;
                    if (progressFill) progressFill.style.width = pct + '%';
                    if (progressHandle) progressHandle.style.left = pct + '%';
                };

                const togglePlay = (e) => {
                    e && e.stopPropagation();
                    if (media.paused) media.play().catch(() => {});
                    else media.pause();
                };

                const seekAudio = (clientX) => {
                    if (!media.duration) return;
                    const rect = progressWrap.getBoundingClientRect();
                    const x = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                    media.currentTime = x * media.duration;
                    updateTime();
                };

                if (progressWrap) {
                    progressWrap.addEventListener('mousedown', (e) => {
                        e.stopPropagation();
                        e.preventDefault();
                        player.setAttribute('data-dragging', 'true');
                        const move = (ev) => seekAudio(ev.clientX);
                        const up = () => {
                            player.removeAttribute('data-dragging');
                            window.removeEventListener('mousemove', move);
                            window.removeEventListener('mouseup', up);
                        };
                        window.addEventListener('mousemove', move);
                        window.addEventListener('mouseup', up);
                        seekAudio(e.clientX);
                    });
                    progressWrap.addEventListener('touchstart', (e) => {
                        e.stopPropagation();
                        player.setAttribute('data-dragging', 'true');
                        const move = (ev) => seekAudio(ev.touches[0].clientX);
                        const up = () => {
                            player.removeAttribute('data-dragging');
                            window.removeEventListener('touchmove', move);
                            window.removeEventListener('touchend', up);
                        };
                        window.addEventListener('touchmove', move, { passive: true });
                        window.addEventListener('touchend', up);
                        seekAudio(e.touches[0].clientX);
                    }, { passive: true });
                    progressWrap.addEventListener('click', (e) => {
                        e.stopPropagation();
                        seekAudio(e.clientX);
                    });
                }

                if (playBtn) playBtn.addEventListener('click', togglePlay);
                player.addEventListener('click', (e) => {
                    if (e.target.closest('.msg-audio-play, .msg-audio-download, .msg-audio-progress')) return;
                    togglePlay(e);
                });
                player.addEventListener('contextmenu', (e) => { e.stopPropagation(); e.preventDefault(); });

                media.addEventListener('play', updatePlayIcon);
                media.addEventListener('pause', updatePlayIcon);
                media.addEventListener('timeupdate', updateTime);
                media.addEventListener('loadedmetadata', () => {
                    updateTime();
                    keepChatAtBottomAfterMedia(media);
                });
                media.addEventListener('ended', () => { media.currentTime = 0; updatePlayIcon(); updateTime(); });

                updatePlayIcon();
                updateTime();
            });

            root.querySelectorAll('.dump-player:not([data-dump-initialized])').forEach(player => {
                player.setAttribute('data-dump-initialized', '1');
                const media = player.querySelector('video, audio');
                if (!media) return;
                media.src = player.dataset.src;
                media.load();

                const isVideo = player.classList.contains('dump-player-video');
                const playBtn = player.querySelector('.dump-player-play');
                const bigPlay = player.querySelector('.dump-player-big-play');
                const muteBtn = player.querySelector('.dump-player-mute');
                const progressWrap = player.querySelector('.dump-player-progress');
                const progressFill = player.querySelector('.dump-player-progress-fill');
                const progressHandle = player.querySelector('.dump-player-progress-handle');
                const volumeWrapOuter = player.querySelector('.dump-player-volume-wrap');
                const volumeWrap = player.querySelector('.dump-player-volume');
                const volumeFill = player.querySelector('.dump-player-volume-fill');
                const timeEl = player.querySelector('.dump-player-time');
                const durationEl = player.querySelector('.dump-player-duration');
                const loader = player.querySelector('.dump-player-loader');
                const fsBtn = player.querySelector('.dump-player-fullscreen');

                const updatePlayIcon = () => {
                    const icon = media.paused ? '<i class="ph-fill ph-play"></i>' : '<i class="ph-fill ph-pause"></i>';
                    if (playBtn) playBtn.innerHTML = icon;
                    if (bigPlay) bigPlay.innerHTML = media.paused ? '<i class="ph-fill ph-play"></i>' : '<i class="ph-fill ph-pause"></i>';
                    if (isVideo && !media.paused) player.classList.add('playing');
                    else if (isVideo) player.classList.remove('playing');
                };

                const updateTime = () => {
                    if (timeEl) timeEl.textContent = formatPlayerTime(media.currentTime);
                    if (durationEl) durationEl.textContent = formatPlayerTime(media.duration || 0);
                    const pct = media.duration ? (media.currentTime / media.duration) * 100 : 0;
                    if (progressFill) progressFill.style.width = pct + '%';
                    if (progressHandle) progressHandle.style.left = pct + '%';
                };

                const togglePlay = (e) => {
                    if (e) e.stopPropagation();
                    if (media.paused) media.play().catch(() => {});
                    else media.pause();
                };

                const toggleMute = (e) => {
                    if (e) e.stopPropagation();
                    media.muted = !media.muted;
                    if (volumeWrapOuter) volumeWrapOuter.classList.toggle('volume-open');
                    if (muteBtn) {
                        muteBtn.innerHTML = media.muted
                            ? '<i class="ph-fill ph-speaker-x"></i>'
                            : (media.volume > 0.5 ? '<i class="ph-fill ph-speaker-high"></i>' : '<i class="ph-fill ph-speaker-low"></i>');
                    }
                    if (volumeFill) volumeFill.style.width = media.muted ? '0%' : (media.volume * 100) + '%';
                };

                const seek = (clientX) => {
                    if (!media.duration || !progressWrap) return;
                    const rect = progressWrap.getBoundingClientRect();
                    const x = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                    media.currentTime = x * media.duration;
                    updateTime();
                };

                const setVolume = (clientX) => {
                    if (!volumeWrap) return;
                    const rect = volumeWrap.getBoundingClientRect();
                    const x = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                    media.volume = x;
                    media.muted = x === 0;
                    if (volumeFill) volumeFill.style.width = (x * 100) + '%';
                    if (muteBtn) {
                        muteBtn.innerHTML = media.muted || x === 0
                            ? '<i class="ph-fill ph-speaker-x"></i>'
                            : (x > 0.5 ? '<i class="ph-fill ph-speaker-high"></i>' : '<i class="ph-fill ph-speaker-low"></i>');
                    }
                };

                if (playBtn) playBtn.addEventListener('click', togglePlay);
                if (bigPlay) bigPlay.addEventListener('click', togglePlay);
                if (muteBtn) muteBtn.addEventListener('click', toggleMute);

                if (progressWrap) {
                    progressWrap.addEventListener('click', (e) => { e.stopPropagation(); seek(e.clientX); });
                    progressWrap.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        __dumpDragState = { target: progressWrap, type: 'progress' };
                        seek(e.clientX);
                    });
                    progressWrap.addEventListener('touchstart', (e) => {
                        e.stopPropagation();
                        __dumpDragState = { target: progressWrap, type: 'progress' };
                        seek(e.touches[0].clientX);
                    }, {passive: true});
                }

                if (volumeWrap) {
                    volumeWrap.addEventListener('click', (e) => { e.stopPropagation(); setVolume(e.clientX); });
                    volumeWrap.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        __dumpDragState = { target: volumeWrap, type: 'volume' };
                        setVolume(e.clientX);
                    });
                }

                player.addEventListener('contextmenu', (e) => { e.stopPropagation(); e.preventDefault(); });

                media.addEventListener('play', updatePlayIcon);
                media.addEventListener('pause', updatePlayIcon);
                media.addEventListener('timeupdate', updateTime);
                media.addEventListener('loadedmetadata', () => {
                    updateTime();
                    keepChatAtBottomAfterMedia(media);
                });
                media.addEventListener('waiting', () => { if (loader) loader.classList.add('active'); });
                media.addEventListener('playing', () => { if (loader) loader.classList.remove('active'); });
                media.addEventListener('canplay', () => { if (loader) loader.classList.remove('active'); });
                media.addEventListener('ended', () => { media.currentTime = 0; updatePlayIcon(); updateTime(); });
                media.addEventListener('volumechange', () => {
                    if (volumeFill) volumeFill.style.width = media.muted ? '0%' : (media.volume * 100) + '%';
                    if (muteBtn) {
                        muteBtn.innerHTML = media.muted || media.volume === 0
                            ? '<i class="ph-fill ph-speaker-x"></i>'
                            : (media.volume > 0.5 ? '<i class="ph-fill ph-speaker-high"></i>' : '<i class="ph-fill ph-speaker-low"></i>');
                    }
                });

                if (isVideo && player) {
                    player.addEventListener('click', (e) => {
                        e.stopPropagation();
                        if (e.target.closest('.dump-player-controls, .dump-player-download, .dump-player-fullscreen, .dump-player-progress, .dump-player-volume, .dump-player-volume-wrap')) return;
                        togglePlay(e);
                    });
                    if (fsBtn) {
                        fsBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (document.fullscreenElement) document.exitFullscreen();
                            else player.requestFullscreen().catch(() => media.requestFullscreen().catch(() => {}));
                        });
                    }
                }

                player.addEventListener('click', (e) => { e.stopPropagation(); });

                const fsChange = () => {
                    const isFs = document.fullscreenElement === player || document.fullscreenElement === media;
                    player.classList.toggle('fullscreen', isFs);
                };
                document.addEventListener('fullscreenchange', fsChange);
                document.addEventListener('webkitfullscreenchange', fsChange);

                updatePlayIcon();
                updateTime();
            });
        }
        window.initDumpPlayers = initDumpPlayers;

        async function renderMessages(keepPosition = false) {
            const container = document.getElementById('chatMessagesInner');
            const scrollContainer = document.getElementById('chatMessages');
            const msgs = messengerMessages[currentConvId] || [];
            const isInitialRender = container.childElementCount === 0;

            if (!msgs.length) {
                container.innerHTML = `<div class="empty-state" style="min-height:200px;"><i class="ph ph-chat-circle"></i><p>Нет сообщений. Напишите первым!</p></div>`;
                return;
            }

            let html = '';
            let lastSenderId = null;
            let lastDateKey = '';
            let lastTimestamp = null;

            msgs.forEach((msg) => {
                const isMe = msg.sender_id == currentUser.id;
                const displayContent = msg.content || '';
                const msgDate = parseDateStr(msg.created_at);
                const currentDateKey = messageDateKey(msgDate);
                const isNewDate = Boolean(currentDateKey && currentDateKey !== lastDateKey);
                const currentTimestamp = msgDate && !isNaN(msgDate) ? msgDate.getTime() : null;
                const hasLongGap = lastTimestamp !== null && currentTimestamp !== null
                    && currentTimestamp - lastTimestamp >= 5 * 60 * 60 * 1000;
                const isFirstOfGroup = msg.sender_id !== lastSenderId || isNewDate || hasLongGap;
                const rowSpacingClass = hasLongGap && !isNewDate ? ' msg-long-gap' : '';

                if (isNewDate) {
                    html += `<div class="msg-date-divider" role="separator"><span>${messageDateLabel(msgDate)}</span></div>`;
                }

                let statusIcon = '';
                if (isMe) {
                    if (msg.my_status === 'read') {
                        statusIcon = `<svg class="msg-status msg-status-read" width="14" height="9" viewBox="0 0 16 10"><path d="M1 5l3 3 5-6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 5l3 3 5-6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
                    } else if (msg.my_status === 'delivered') {
                        statusIcon = `<svg class="msg-status msg-status-delivered" width="14" height="9" viewBox="0 0 16 10"><path d="M1 5l3 3 5-6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 5l3 3 5-6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
                    } else {
                        statusIcon = `<svg class="msg-status" width="14" height="9" viewBox="0 0 16 10"><path d="M1 5l3 3 5-6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
                    }
                }

                const time = msgTimeShort(msg.created_at);
                const isEdited = msg.edited_at ? ' (ред.)' : '';
                const isDeleted = msg.deleted_at ? true : false;
                const avatar = msg.avatar_url ? getProxyUrl(msg.avatar_url || `https://ui-avatars.com/api/?name=${msg.username}&background=random`) : '';

                if (isDeleted) {
                    html += `<div class="msg-row ${isMe ? 'msg-row-me' : ''}${rowSpacingClass}">
                        <div class="msg-deleted">Сообщение удалено</div>
                    </div>`;
                    lastSenderId = msg.sender_id;
                    if (currentDateKey) lastDateKey = currentDateKey;
                    if (currentTimestamp !== null) lastTimestamp = currentTimestamp;
                    return;
                }

                let replyHtml = '';
                if (msg.reply_to) {
                    const replyMsg = msgs.find(m => m.id == msg.reply_to);
                    if (replyMsg) {
                        const replyContent = replyMsg.content || '';
                        replyHtml = `<div class="msg-reply" onclick="scrollToMsg(${msg.reply_to})">
                            <div class="msg-reply-name">${replyMsg.username}</div>
                            <div class="msg-reply-text">${escHtml(replyContent.substring(0, 80))}</div>
                        </div>`;
                    }
                }

                html += `<div class="msg-row ${isMe ? 'msg-row-me' : ''}${rowSpacingClass}" id="msg-${msg.id}">
                    ${!isMe && isFirstOfGroup ? `<img src="${avatar}" class="msg-avatar" loading="lazy">` : ''}
                    ${!isMe && !isFirstOfGroup ? '<div class="msg-avatar-spacer"></div>' : ''}
                    <div class="msg-content ${isMe ? 'msg-content-me' : ''} ${isFirstOfGroup ? '' : 'msg-continue'}">
                        ${!isMe && isFirstOfGroup ? `<div class="msg-author">${msg.username}</div>` : ''}
                        ${replyHtml}
                        <div class="msg-bubble" onclick="showMsgActions(${msg.id}, event)" oncontextmenu="event.preventDefault(); showMsgActions(${msg.id}, event);">
                            <div class="msg-text">${renderMsgBody(displayContent)}</div>
                            <div class="msg-meta">
                                <span class="msg-time">${time}${isEdited}</span>
                                ${isMe ? statusIcon : ''}
                            </div>
                        </div>
                    </div>
                </div>`;

                lastSenderId = msg.sender_id;
                if (currentDateKey) lastDateKey = currentDateKey;
                if (currentTimestamp !== null) lastTimestamp = currentTimestamp;
            });

            const prevScrollHeight = scrollContainer ? scrollContainer.scrollHeight : 0;
            const prevScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;
            const wasNearBottom = isChatNearBottom(scrollContainer);

            const playerStates = [];
            container.querySelectorAll('.dump-player, .msg-audio-widget').forEach(p => {
                const media = p.querySelector('video, audio');
                if (media && media.src && media.currentTime > 0) {
                    playerStates.push({
                        src: media.src,
                        currentTime: media.currentTime,
                        paused: media.paused,
                        muted: media.muted,
                        volume: media.volume,
                    });
                }
            });

            container.innerHTML = html;
            initDumpPlayers(container);
            if (isInitialRender) beginChatBottomPin(container);

            if (playerStates.length) {
                container.querySelectorAll('.dump-player, .msg-audio-widget').forEach(p => {
                    const media = p.querySelector('video, audio');
                    if (!media || !media.src) return;
                    const state = playerStates.find(s => s.src === media.src);
                    if (state) {
                        media.muted = state.muted;
                        media.volume = state.volume;
                        media.currentTime = state.currentTime;
                        if (!state.paused) media.play().catch(() => {});
                    }
                });
            }

            if (keepPosition && scrollContainer) {
                const newH = scrollContainer.scrollHeight;
                scrollContainer.scrollTop = newH - prevScrollHeight + prevScrollTop;
            } else if (scrollContainer) {
                scrollContainer.scrollTop = wasNearBottom ? scrollContainer.scrollHeight : prevScrollTop;
                requestAnimationFrame(() => {
                    if (currentConvId == renderedConvId) onChatScroll();
                });
            }
        }

        function isChatNearBottom(container, threshold = 80) {
            if (!container) return false;
            return container.scrollHeight - container.clientHeight - container.scrollTop <= threshold;
        }

        let chatBottomPinUntil = 0;
        let chatResizeObserver = null;

        function beginChatBottomPin(content) {
            chatBottomPinUntil = Date.now() + 4000;
            if (chatResizeObserver) chatResizeObserver.disconnect();
            if (window.ResizeObserver) {
                chatResizeObserver = new ResizeObserver(() => {
                    if (Date.now() < chatBottomPinUntil) scrollChatDown();
                });
                chatResizeObserver.observe(content);
            }
            scrollChatDown();
            requestAnimationFrame(scrollChatDown);
            setTimeout(() => {
                if (Date.now() < chatBottomPinUntil) scrollChatDown();
            }, 100);
        }

        function releaseChatBottomPin() {
            chatBottomPinUntil = 0;
        }

        function keepChatAtBottomAfterMedia(element) {
            const container = document.getElementById('chatMessages');
            if (!container || !element.closest('#chatMessagesInner')) return;
            if (Date.now() < chatBottomPinUntil || isChatNearBottom(container, 320)) {
                requestAnimationFrame(scrollChatDown);
            }
        }

        function scrollChatDown() {
            const container = document.getElementById('chatMessages');
            if (container) container.scrollTop = container.scrollHeight;
        }

        const MESSAGE_PAGE_SIZE = 50;
        let isLoadingMoreMessages = false;
        let historyLoadConversationId = null;
        let historyLoadTimer = null;
        const messageHistoryHasMore = Object.create(null);

        function updateChatHistoryControls() {
            const wrapper = document.getElementById('chatLoadMore');
            const button = wrapper?.querySelector('button');
            if (!wrapper || !button || !currentConvId) return;

            const hasMore = messageHistoryHasMore[currentConvId] !== false;
            wrapper.classList.remove('hidden');
            wrapper.classList.toggle('history-exhausted', !hasMore);
            button.disabled = isLoadingMoreMessages || !hasMore;
            button.textContent = isLoadingMoreMessages ? 'Загрузка...' : 'Загрузить ещё';
        }

        async function loadMoreMessages() {
            const convId = currentConvId;
            const msgs = messengerMessages[currentConvId] || [];
            if (!convId || !msgs.length || isLoadingMoreMessages || messageHistoryHasMore[convId] === false) return;
            const before = msgs[0].id;
            isLoadingMoreMessages = true;
            historyLoadConversationId = convId;
            updateChatHistoryControls();

            if (wsConnected) {
                ws.send(JSON.stringify({ type: 'get_messages', conversation_id: convId, before }));
                clearTimeout(historyLoadTimer);
                historyLoadTimer = setTimeout(() => {
                    if (historyLoadConversationId == convId) {
                        historyLoadConversationId = null;
                        isLoadingMoreMessages = false;
                        updateChatHistoryControls();
                    }
                }, 10000);
                return;
            }

            try {
                const res = await fetch(apiCall('messages') + `&conversation_id=${convId}&before=${before}`);
                const data = await res.json();
                const page = data.success && Array.isArray(data.messages) ? data.messages : [];
                if (data.success) {
                    const existing = messengerMessages[convId] || [];
                    const older = page.filter(message => !existing.some(item => item.id == message.id));
                    messengerMessages[convId] = [...older, ...existing];
                    messageHistoryHasMore[convId] = page.length >= MESSAGE_PAGE_SIZE;
                    if (currentConvId == convId) renderMessages(true);
                }
            } catch (e) {
                console.error('loadMoreMessages error:', e);
            } finally {
                if (historyLoadConversationId == convId) historyLoadConversationId = null;
                isLoadingMoreMessages = false;
                updateChatHistoryControls();
            }
        }
        window.loadMoreMessages = loadMoreMessages;

        let _chatScrollFrame = null;
        function onChatScroll() {
            const container = document.getElementById('chatMessages');
            if (!container || isLoadingMoreMessages) return;
            if (_chatScrollFrame) return;
            _chatScrollFrame = requestAnimationFrame(() => {
                _chatScrollFrame = null;
                const threshold = Math.max(120, container.clientHeight * 0.2);
                if (container.scrollTop <= threshold) loadMoreMessages();
            });
        }

        window.scrollToMsg = function(msgId) {
            const el = document.getElementById('msg-' + msgId);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.style.background = 'rgba(255,255,255,0.05)';
                setTimeout(() => el.style.background = '', 2000);
            }
        };

        async function sendChatMessage(e) {
            e.preventDefault();
            const input = document.getElementById('chatInput');
            let content = input.value.trim();
            if (pendingAttachments.length) {
                content = (content ? content + '\n' : '') + pendingAttachments.map(attachment => attachment.content).join('\n');
            }
            if (!content) return;
            if (!currentConvId) return;
            if (String(currentConvId).startsWith('pending_')) return;

            if (content.length > 5000) {
                showToast('Сообщение слишком длинное (максимум 5000 символов)');
                return;
            }

            if (captchaRequired) {
                _pendingSpamMessage = () => sendChatMessage(e);
                showSpamCaptcha();
                return;
            }

            const partnerId = currentConvPartner ? currentConvPartner.id : null;

            if (editMessageId) {
                if (wsConnected) {
                    ws.send(JSON.stringify({
                        type: 'edit_message',
                        message_id: editMessageId,
                        conversation_id: currentConvId,
                        content,
                    }));
                }
                editMessageId = null;
                document.getElementById('editIndicator').classList.add('hidden');
                input.value = '';
                input.style.height = 'auto';
                document.getElementById('chatSendBtn').innerHTML = '<i class="ph-fill ph-paper-plane-right"></i>';
                return;
            }

            const tempId = -Date.now();
            if (currentConvId) {
                if (!messengerMessages[currentConvId]) messengerMessages[currentConvId] = [];
                messengerMessages[currentConvId].push({
                    id: tempId,
                    sender_id: currentUser.id,
                    content,
                    my_status: 'sent',
                    created_at: new Date().toISOString(),
                    username: currentUser.username,
                    avatar_url: currentUser.avatar_url,
                });
                renderMessages();
                scrollChatDown();
            }

            if (wsConnected) {
                _pendingWsContent = content;
                _pendingWsReply = replyToMessage ? replyToMessage.id : null;
                ws.send(JSON.stringify({
                    type: 'send_message',
                    conversation_id: currentConvId,
                    content: content,
                    reply_to: replyToMessage ? replyToMessage.id : null,
                }));
            } else {
                try {
                    const fd = new FormData();
                    fd.append('conversation_id', currentConvId);
                    fd.append('content', content);
                    fd.append('reply_to', replyToMessage ? replyToMessage.id : 0);
                    fd.append('csrf_token', csrfToken);
                    const res = await fetch(apiCall('message_send'), { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success && data.message) {
                        if (!messengerMessages[currentConvId]) messengerMessages[currentConvId] = [];
                        const tempIdx = messengerMessages[currentConvId].findIndex(m => m.id < 0 && m.content === data.message.content);
                        if (tempIdx !== -1) messengerMessages[currentConvId].splice(tempIdx, 1);
                        messengerMessages[currentConvId].push(data.message);
                        renderMessages();
                        scrollChatDown();
                        cancelReply();
                        input.value = '';
                        input.style.height = 'auto';
                        pendingAttachments = [];
                        renderAttachmentPreviews();
                    } else if (data.require_captcha) {
                        captchaRequired = true;
                        _pendingSpamMessage = () => sendChatMessage(e);
                        showSpamCaptcha();
                    } else {
                        showToast(data.error || 'Ошибка');
                        cancelReply();
                        input.value = '';
                        input.style.height = 'auto';
                    }
                } catch (e) {
                    showToast('Ошибка отправки');
                }
            }

            if (wsConnected) {
                cancelReply();
                input.value = '';
                input.style.height = 'auto';
                pendingAttachments = [];
                renderAttachmentPreviews();
            }
        }

        function markAsRead(convId) {
            if (wsConnected) {
                ws.send(JSON.stringify({ type: 'mark_read', conversation_id: convId }));
            }
        }

        function onChatTyping() {
            if (!wsConnected || !currentConvId) return;
            if (!isTyping) {
                isTyping = true;
                ws.send(JSON.stringify({ type: 'typing', conversation_id: currentConvId, is_typing: true }));
            }
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                isTyping = false;
                if (wsConnected) {
                    ws.send(JSON.stringify({ type: 'typing', conversation_id: currentConvId, is_typing: false }));
                }
            }, 2000);
        }

        window.replyToMsg = function(msgId) {
            const msgs = messengerMessages[currentConvId] || [];
            const msg = msgs.find(m => m.id === msgId);
            if (msg) {
                replyToMessage = msg;
                document.getElementById('replyToName').textContent = msg.username;
                document.getElementById('replyIndicator').classList.remove('hidden');
                document.getElementById('chatInput').focus();
            }
        };

        function cancelReply() {
            replyToMessage = null;
            document.getElementById('replyIndicator').classList.add('hidden');
        }

        function cancelEdit() {
            editMessageId = null;
            document.getElementById('editIndicator').classList.add('hidden');
            document.getElementById('chatInput').value = '';
            document.getElementById('chatSendBtn').innerHTML = '<i class="ph-fill ph-paper-plane-right"></i>';
        }

        window.copyMsg = function(msgId) {
            const msgs = messengerMessages[currentConvId] || [];
            const msg = msgs.find(m => m.id === msgId);
            if (!msg || !msg.content) return;
            navigator.clipboard.writeText(msg.content).then(() => {
                showToast('Сообщение скопировано');
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = msg.content;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                showToast('Сообщение скопировано');
            });
        };

        window.showMsgActions = function(msgId, event) {
            const msgs = messengerMessages[currentConvId] || [];
            const msg = msgs.find(m => m.id === msgId);
            if (!msg) return;
            const isMe = msg.sender_id == currentUser.id;

            const actions = [];
            actions.push({ label: 'Ответить', icon: 'ph-arrow-u-down-left', action: `replyToMsg(${msgId})` });
            actions.push({ label: 'Копировать', icon: 'ph-copy', action: `copyMsg(${msgId})` });

            if (isMe) {
                actions.push({ label: 'Редактировать', icon: 'ph-pencil', action: `editMsg(${msgId})` });
                actions.push({ label: 'Удалить', icon: 'ph-trash', action: `deleteMsg(${msgId})`, danger: true });
            }

            showContextMenu(actions, event);
        };

        function showContextMenu(actions, event) {
            const existing = document.getElementById('contextMenu');
            if (existing) existing.remove();

            const menu = document.createElement('div');
            menu.id = 'contextMenu';
            menu.className = 'context-menu';
            menu.innerHTML = actions.map(a => `
                <button class="context-menu-item ${a.danger ? 'context-menu-danger' : ''}" onclick="(${a.action}); this.closest('.context-menu').remove();">
                    <i class="ph ${a.icon}"></i> ${a.label}
                </button>
            `).join('');

            document.body.appendChild(menu);

            const x = event ? event.clientX : window.innerWidth / 2;
            const y = event ? event.clientY : window.innerHeight / 2;

            menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 200) + 'px';
            menu.style.transform = 'scale(0.95)';
            menu.style.opacity = '0';

            void menu.offsetWidth;
            menu.style.transition = 'all 0.15s';
            menu.style.transform = 'scale(1)';
            menu.style.opacity = '1';

            const close = (e) => {
                if (!menu.contains(e.target)) {
                    menu.remove();
                    document.removeEventListener('click', close);
                    document.removeEventListener('contextmenu', close);
                }
            };
            setTimeout(() => {
                document.addEventListener('click', close);
                document.addEventListener('contextmenu', close);
            }, 100);
        }

        window.showConvContextMenu = function(convId, event) {
            const conv = messengerConversations.find(c => c.id === convId);
            if (!conv) return;
            const partner = conv.participants && conv.participants[0];
            if (!partner) return;

            const blocked = isUserBlocked(partner.id);
            const muted = !!conv.muted;

            const actions = [
                { label: 'Очистить чат', icon: 'ph-eraser', action: `clearConv(${convId})` },
                { label: muted ? 'Включить уведомления' : 'Заглушить', icon: muted ? 'ph-bell-ringing' : 'ph-bell-slash', action: `toggleMuteConv(${convId})` },
                { label: blocked ? 'Разблокировать' : 'Заблокировать', icon: 'ph-prohibit', action: `toggleBlockUser(${convId}, ${partner.id}, '${partner.username.replace(/'/g, "\\'")}')`, danger: !blocked },
                { label: 'Удалить чат', icon: 'ph-trash', action: `leaveConv(${convId})`, danger: true },
            ];

            if (isDumpApp || /Mobi|Android/i.test(navigator.userAgent)) {
                showConvActionsModal(partner.username, actions);
            } else {
                showContextMenu(actions, event);
            }
        };

        function showConvActionsModal(username, actions) {
            const existing = document.getElementById('convActionsModal');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.id = 'convActionsModal';
            overlay.className = 'modal-overlay modal-bottom';
            overlay.style.zIndex = '9999';
            overlay.onclick = function(e) {
                if (e.target === overlay) overlay.remove();
            };

            overlay.innerHTML = `
                <div class="modal-content" style="padding-bottom: 2rem;">
                    <div class="flex justify-center mb-6">
                        <div style="width:40px;height:5px;background:var(--surface-hover);border-radius:4px;"></div>
                    </div>
                    <h3 class="font-bold mb-4" style="font-size:1.1rem;text-align:center;">${username}</h3>
                    <div class="flex flex-col gap-2">
                        ${actions.map(a => `
                            <button class="vc-btn-outline flex items-center justify-start gap-3" style="border:none;background:var(--surface-elevated);padding:16px 20px;${a.danger ? 'color:var(--error);' : ''}"
                                onclick="(${a.action}); document.getElementById('convActionsModal').remove();">
                                <i class="ph ${a.icon}" style="font-size:1.35rem;"></i>
                                <span style="font-size:1rem;">${a.label}</span>
                            </button>
                        `).join('')}
                        <button class="vc-btn-outline flex items-center justify-center gap-3" style="border:none;background:var(--surface-elevated);padding:16px 20px;margin-top:0.5rem;"
                            onclick="document.getElementById('convActionsModal').remove();">
                            <span style="font-size:1rem;">Отмена</span>
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);
            overlay.classList.add('open');
        }

        window.leaveConv = function(convId) {
            showConfirm('Удаление', 'Удалить этот чат?', () => {
                if (wsConnected) {
                    ws.send(JSON.stringify({ type: 'leave_conversation', conversation_id: convId }));
                } else {
                    fetch(apiCall('leave_conversation'), {
                        method: 'POST',
                        body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&conversation_id=' + convId,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    }).then(() => {
                        messengerConversations = messengerConversations.filter(c => c.id != convId);
                        if (currentConvId == convId) showConvList();
                        renderConvList();
                    });
                }
            });
        };

        window.clearConv = function(convId) {
            showConfirm('Очистка', 'Очистить историю сообщений?', () => {
                if (wsConnected) {
                    ws.send(JSON.stringify({ type: 'clear_conversation', conversation_id: convId }));
                } else {
                    fetch(apiCall('clear_conversation'), {
                        method: 'POST',
                        body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&conversation_id=' + convId,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                    }).then(() => {
                        messengerMessages[convId] = [];
                        if (currentConvId == convId) { renderMessages(); }
                    });
                }
            });
        };

        window.toggleMuteConv = function(convId) {
            const conv = messengerConversations.find(c => c.id === convId);
            if (!conv) return;
            const mute = !conv.muted;
            conv.muted = mute;
            if (wsConnected) {
                ws.send(JSON.stringify({ type: mute ? 'mute_conversation' : 'unmute_conversation', conversation_id: convId }));
            } else {
                fetch(apiCall(mute ? 'mute_conversation' : 'unmute_conversation'), {
                    method: 'POST',
                    body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&conversation_id=' + convId,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
            }
            renderConvList();
            showToast(mute ? 'Уведомления отключены' : 'Уведомления включены');
        };

        window.toggleBlockUser = function(convId, userId, username) {
            if (isUserBlocked(userId)) {
                showConfirm('Разблокировка', `Разблокировать ${username}?`, () => {
                    if (wsConnected) {
                        ws.send(JSON.stringify({ type: 'unblock_user', user_id: userId }));
                    } else {
                        fetch(apiCall('unblock_user'), {
                            method: 'POST',
                            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&user_id=' + userId,
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                        }).then(() => {
                            _blockedUserIds.delete(userId);
                            showToast(username + ' разблокирован(а)');
                            renderConvList();
                            if (currentConvId == convId && currentConvPartner) {
                                openChat(messengerConversations.find(c => c.id === convId) || { id: convId, participants: [currentConvPartner] });
                            }
                        });
                    }
                });
            } else {
                showConfirm('Блокировка', `Заблокировать ${username}? Вы больше не будете получать от него сообщения.`, () => {
                    if (wsConnected) {
                        ws.send(JSON.stringify({ type: 'block_user', user_id: userId }));
                    } else {
                        fetch(apiCall('block_user'), {
                            method: 'POST',
                            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&user_id=' + userId,
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                        }).then(() => {
                            _blockedUserIds.add(userId);
                            if (currentConvId == convId && currentConvPartner) {
                                openChat(messengerConversations.find(c => c.id === convId) || { id: convId, participants: [currentConvPartner] });
                            }
                            renderConvList();
                            showToast(username + ' заблокирован(а)');
                        });
                    }
                });
            }
        }

        window.blockConvUser = window.toggleBlockUser;

        window.editMsg = function(msgId) {
            const msgs = messengerMessages[currentConvId] || [];
            const msg = msgs.find(m => m.id === msgId);
            if (!msg) return;
            editMessageId = msgId;
            document.getElementById('chatInput').value = msg.content || '';
            document.getElementById('editIndicator').classList.remove('hidden');
            document.getElementById('chatSendBtn').innerHTML = '<i class="ph ph-check"></i>';
            document.getElementById('chatInput').focus();
            resizeTextarea(document.getElementById('chatInput'));
        };

        window.deleteMsg = function(msgId) {
            showConfirm('Удаление', 'Удалить это сообщение?', () => {
                const msgs = messengerMessages[currentConvId] || [];
                const idx = msgs.findIndex(m => m.id === msgId);
                if (idx !== -1) {
                    msgs.splice(idx, 1);
                    renderMessages();
                }
                if (wsConnected) {
                    ws.send(JSON.stringify({
                        type: 'delete_message',
                        message_id: msgId,
                        conversation_id: currentConvId,
                    }));
                }
            });
        };

        function openChatEmoji() {
            const picker = document.getElementById('emojiPicker');
            const grid = document.getElementById('emojiGrid');
            const gifGrid = document.getElementById('gifGrid');
            if (!picker || !grid) return;
            if (picker.classList.contains('hidden')) {
                if (!grid.innerHTML.trim()) {
                    const emojis = ['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','🥰','😘','😜','🤪','😝','🤑','🤗','🤩','🤔','🤨','😐','😑','😶','🙄','😏','😣','😥','😮','🤐','😯','😪','😫','😴','😌','😛','😜','😝','🤤','😒','😓','😔','😕','🙃','🤑','😲','☹️','🙁','😖','😞','😟','😤','😢','😭','😦','😧','😨','😩','🤯','😬','😰','😱','🥵','🥶','😳','🤪','😵','😡','😠','🤬','👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🫰','🫵','🤟','🤘','🤙','👈','👉','👆','🖕','👇','👍','👎','👊','✊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦵','🦶','👂','🦻','👃','🧠','🫀','🫁','🦷','🦴','👀','👁','👅','👄','❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','♥️','🫶','💌','💋','💯','💢','💥','💫','💦','💨','🕳️','💬','👤','👥','🗣️','🧑','👱','👨','👩','🧔','👴','👵','🙋','💁','🙅','🙆','🙇','🤦','🤷','👑','👒','🎩','🎓','🧢','👑','💄','💍','🌍','🌎','🌏','🌐','☀️','🌑','⭐','🌟','🔥','💧','🌊','🍕','🍔','🍟','🌭','🥪','🥙','🧆','🥗','🍿','🧁','🍰','🎂','🍦','🍩','🍪','🍫','🍬','🍭','🍮','☕','🍵','🍺','🍻','🥂','🥃','🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🪱','🐛','🦋','🐌','🐞','🐜','🪰','🪲','🪳','🦟','🦗','🕷️','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🌹','🥀','🌺','🌸','🌼','🌻','🌞','🌝','🌛','🌜','🌚','🌕','🌖','🌗','🌘','🌑','🌒','🌓','🌔','🌙','🌎','🌍','🌏','🌈','☁️','⛅','⚡','❄️','🔥','💥','⭐','🌟','✨','💫','🎉','🎊','🎈','🎁','🎀','🎃','🎄','🎆','🎇','🧨','✨','🪄','💎','🔮','🎮','🎯','🎲','♟️','🏆','🥇','🥈','🥉','⚽','⚾','🏀','🏐','🏈','🎾','🏉','🎱','🎳','⛳','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛸️','🎣','🤿','🎽','🎿','🛷','🥌','🎯','🎰','🎲','♠️','♥️','♦️','♣️','🃏','🀄️','🎴'];
                    grid.innerHTML = emojis.map(e => `<button type="button" class="emoji-item" onclick="insertEmoji('${e}')">${e}</button>`).join('');
                }
                if (!gifGrid.innerHTML.trim()) {
                    gifGrid.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);">Введите запрос для поиска GIF</div>';
                }
                switchPickerTab('emoji');
                picker.classList.remove('hidden');
            } else {
                picker.classList.add('hidden');
            }
        }

        window.openChatEmoji = openChatEmoji;

        function switchPickerTab(tab) {
            document.getElementById('tabEmoji').classList.toggle('active', tab === 'emoji');
            document.getElementById('tabGif').classList.toggle('active', tab === 'gif');
            document.getElementById('emojiPanel').classList.toggle('hidden', tab !== 'emoji');
            document.getElementById('gifPanel').classList.toggle('hidden', tab !== 'gif');
            if (tab === 'gif') {
                const input = document.getElementById('gifSearchInput');
                const grid = document.getElementById('gifGrid');
                setTimeout(() => input?.focus(), 100);
                if (grid && grid.dataset.gifQuery !== (input?.value.trim() || '')) {
                    performGifSearch();
                }
            }
        }
        window.switchPickerTab = switchPickerTab;

        let gifSearchTimeout = null;
        function debounceGifSearch() {
            clearTimeout(gifSearchTimeout);
            gifSearchTimeout = setTimeout(performGifSearch, 400);
        }
        window.debounceGifSearch = debounceGifSearch;

        async function performGifSearch() {
            const input = document.getElementById('gifSearchInput');
            const q = input.value.trim();
            const grid = document.getElementById('gifGrid');
            grid.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);"><i class="ph ph-spinner spin"></i></div>';
            try {
                const res = await fetch(apiCall('gif_search') + '&q=' + encodeURIComponent(q));
                const data = await res.json();
                if (data.success && data.gifs.length) {
                    grid.dataset.gifQuery = q;
                    grid.innerHTML = data.gifs.map(g => {
                        const imageUrl = g.gif_url || g.image_url || '';
                        const url = imageUrl.replace(/'/g, "\\'");
                        const desc = (g.title || g.description || 'GIF').replace(/'/g, "\\'");
                        return `<div class="gif-item" onclick="insertGif('${url}')" title="${desc}"><img src="${getProxyUrl(imageUrl)}" alt="" loading="lazy"></div>`;
                    }).join('');
                } else {
                    grid.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);">Ничего не найдено</div>';
                }
            } catch (e) {
                grid.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);">Ошибка поиска</div>';
            }
        }

        async function insertGif(url) {
            const input = document.getElementById('chatInput');
            if (!input || !url) return;

            // Send the GIF as a normal message instead of inserting a raw URL.
            if (!currentConvId || String(currentConvId).startsWith('pending_')) {
                input.value += (input.value ? ' ' : '') + url + ' ';
                input.focus();
                resizeTextarea(input);
                closeEmojiPicker();
                return;
            }

            const draft = input.value;
            const attachments = pendingAttachments.slice();
            input.value = url;
            pendingAttachments = [];
            renderAttachmentPreviews();
            closeEmojiPicker();
            await sendChatMessage({ preventDefault() {} });

            // Do not destroy text the user had already typed while choosing a GIF.
            if (!captchaRequired) {
                input.value = draft;
                pendingAttachments = attachments;
                renderAttachmentPreviews();
                input.focus();
                resizeTextarea(input);
            }
        }
        window.insertGif = insertGif;

        function closeEmojiPicker() {
            document.getElementById('emojiPicker').classList.add('hidden');
        }

        let pendingAttachments = [];

        function handleChatFiles(input) {
            const files = Array.from(input.files || []);
            input.value = '';
            if (!files.length) return;
            uploadChatFiles(files);
        }
        window.handleChatFiles = handleChatFiles;

        async function uploadChatFiles(files) {
            const progress = showChatUploadProgress(files.length);
            for (const file of files) {
                const attachment = file.type.startsWith('image/')
                    ? await uploadToImgBBChat(file)
                    : await uploadToDumpStorage(file);
                if (attachment) {
                    pendingAttachments.push(attachment);
                    renderAttachmentPreviews();
                }
            }
            progress.remove();
        }

        function renderAttachmentPreviews() {
            let container = document.getElementById('attachPreviewContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'attachPreviewContainer';
                container.className = 'chat-upload-preview';
                const form = document.getElementById('chatForm');
                form.parentNode.insertBefore(container, form);
            }
            container.innerHTML = pendingAttachments.map((attachment, i) => {
                if (!attachment.isImage) {
                    return `<div class="chat-file-preview">
                        <div class="chat-file-preview-icon">${getFileIcon(attachment.name.split('.').pop().toLowerCase())}</div>
                        <span>${escHtml(attachment.name)}</span>
                        <button type="button" class="remove-preview" onclick="removeAttachment(${i})" aria-label="Удалить вложение"><i class="ph ph-x"></i></button>
                    </div>`;
                }
                const isGif = /\.gif(\?.*)?$/i.test(attachment.content);
                return `<div class="chat-image-preview">
                    <img src="${getProxyUrl(attachment.content)}" alt="" style="width:56px;height:56px;border-radius:8px;object-fit:cover;">
                    ${isGif ? '<span class="chat-image-preview-label">GIF</span>' : ''}
                    <button type="button" class="remove-preview" onclick="removeAttachment(${i})" style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:var(--surface-active);border-radius:50%;font-size:0.7rem;display:flex;align-items:center;justify-content:center;"><i class="ph ph-x"></i></button>
                </div>`;
            }).join('');
            if (!pendingAttachments.length) container.remove();
            document.getElementById('chatInput').focus();
        }

        function removeAttachment(i) {
            pendingAttachments.splice(i, 1);
            renderAttachmentPreviews();
        }
        window.removeAttachment = removeAttachment;

        function showChatUploadProgress(count) {
            const existing = document.getElementById('chatUploadProgress');
            if (existing) existing.remove();
            const el = document.createElement('div');
            el.id = 'chatUploadProgress';
            el.className = 'chat-upload-progress';
            el.innerHTML = `<i class="ph ph-spinner spin spinner"></i> Загрузка ${count} файл${count > 1 ? 'ов' : 'а'}...`;
            const form = document.getElementById('chatForm');
            form.parentNode.insertBefore(el, form);
            return { remove: () => el.remove() };
        }

        async function uploadToImgBBChat(file) {
            const fd = new FormData();
            fd.append('image', file);
            fd.append('csrf_token', csrfToken);
            try {
                const res = await fetch(apiCall('upload_image'), { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Не удалось загрузить изображение');
                    return null;
                }
                return { content: data.url, name: file.name, type: file.type, isImage: true };
            } catch {
                showToast('Ошибка загрузки изображения');
                return null;
            }
        }

        async function uploadToDumpStorage(file) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('csrf_token', csrfToken);
            try {
                const res = await fetch(apiCall('upload_file'), { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Не удалось загрузить файл');
                    return null;
                }
                return { content: data.tag, name: data.file_name || file.name, type: file.type || 'application/octet-stream', isImage: false };
            } catch {
                showToast('Ошибка загрузки файла');
                return null;
            }
        }

        document.addEventListener('paste', (e) => {
            const chatSection = document.getElementById('chatSection');
            if (!chatSection || chatSection.classList.contains('hidden')) return;
            if (document.activeElement !== document.getElementById('chatInput') &&
                !document.getElementById('chatInput').contains(document.activeElement)) return;
            if (!e.clipboardData || !e.clipboardData.items) return;
            const items = Array.from(e.clipboardData.items);
            const files = [];
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    const file = item.getAsFile();
                    if (file) files.push(file);
                }
            }
            if (files.length) {
                e.preventDefault();
                uploadChatFiles(files);
            }
        });

        const chatInputEl = document.getElementById('chatInput');
        if (chatInputEl) {
            chatInputEl.addEventListener('dragover', (e) => { e.preventDefault(); });
            chatInputEl.addEventListener('drop', (e) => {
                e.preventDefault();
                const files = Array.from(e.dataTransfer.files || []);
                if (files.length) uploadChatFiles(files);
            });
        }

        window.insertEmoji = function(emoji) {
            const input = document.getElementById('chatInput');
            input.value += emoji;
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
            resizeTextarea(input);
        };

        function openNewChat() {
            document.getElementById('newChatSearch').value = '';
            document.getElementById('newChatResults').innerHTML = '<div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Начните вводить имя</p></div>';
            openModal('newChatModal', 'newChatSearch');
        }

        let newChatSearchTimeout = null;
        function debounceNewChatSearch() {
            clearTimeout(newChatSearchTimeout);
            newChatSearchTimeout = setTimeout(performNewChatSearch, 400);
        }

        async function performNewChatSearch() {
            const q = document.getElementById('newChatSearch').value.trim();
            const list = document.getElementById('newChatResults');
            if (!q) { list.innerHTML = '<div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Начните вводить имя</p></div>'; return; }

            try {
                const res = await fetch(apiCall('search') + '&q=' + encodeURIComponent(q));
                const data = await res.json();
                const users = (data.users || []).filter(u => u.id !== currentUser.id);
                if (!users.length) {
                    list.innerHTML = '<div class="empty-state"><i class="ph ph-user"></i><p>Пользователи не найдены</p></div>';
                    return;
                }
                list.innerHTML = users.map(u => {
                    const avatar = getProxyUrl(u.avatar_url || `https://ui-avatars.com/api/?name=${u.username}&background=random`);
                    const av = (u.avatar_url || '').replace(/'/g, "\\'");
                    const un = (u.username || '').replace(/'/g, "\\'");
                    return `<div class="search-result-item" onclick="startNewChat(${u.id}, '${un}', '${av}')">
                        <img src="${avatar}" class="search-result-img">
                        <div class="font-bold flex-1">${u.username}</div>
                    </div>`;
                }).join('');
            } catch (e) {
                list.innerHTML = '<div class="text-center py-4 text-muted">Ошибка поиска</div>';
            }
        }

        async function startNewChat(userId, username, avatarUrl) {
            closeModal('newChatModal');

            if (isUserBlocked(userId)) {
                showToast('Пользователь заблокирован');
                return;
            }

            const conv = messengerConversations.find(c => {
                return c.participants && c.participants[0] && c.participants[0].id === userId;
            });

            if (conv) {
                openChat(conv);
                navigate('/messages/' + conv.id, true);
                return;
            }

            try {
                const fd = new FormData();
                fd.append('user_id', userId);
                fd.append('csrf_token', csrfToken);
                const res = await fetch(apiCall('conversation_create'), { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success && data.conversation_id) {
                    const newConv = {
                        id: data.conversation_id,
                        participants: [{ id: userId, username, avatar_url: avatarUrl || null }],
                        last_message: '',
                        last_message_at: null,
                        unread_count: 0,
                    };
                    messengerConversations.unshift(newConv);
                    renderConvList();
                    openChat(newConv);
                    navigate('/messages/' + data.conversation_id, true);
                } else {
                    showToast(data.error || 'Ошибка');
                }
            } catch (e) {
                showToast('Ошибка создания чата');
            }
        }

        function openChatSearch() {
            const input = document.getElementById('chatInput');
            input.focus();
            showToast('Поиск: используйте Ctrl+F в чате');
        }

        /* ─── ПРИВАТНОСТЬ ────────────────────────────── */

        function switchSettingsTab(tab) {
            ['profile', 'account', 'sessions', 'privacy'].forEach(t => {
                document.getElementById('pane' + t.charAt(0).toUpperCase() + t.slice(1)).classList.add('hidden');
                document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1)).classList.remove('active');
            });
            document.getElementById('pane' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.remove('hidden');
            document.getElementById('tabBtn' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');

            if (tab === 'sessions') loadSessions();
            if (tab === 'privacy') loadPrivacySettings();
        }

        async function loadPrivacySettings() {
            try {
                const res = await fetch(apiCall('check_privacy'));
                const data = await res.json();
                if (data.success && data.privacy) {
                    document.getElementById('privacySearchable').checked = data.privacy.privacy_searchable != 0;
                    document.getElementById('privacyMessages').checked = data.privacy.privacy_messages != 0;
                    const beta = data.privacy.privacy_beta != 0;
                    document.getElementById('privacyBeta').checked = beta;
                    if (currentUser) currentUser.privacy_beta = beta ? 1 : 0;
                    const noAds = data.privacy.privacy_no_ads != 0;
                    document.getElementById('privacyNoAds').checked = noAds;
                    if (currentUser) currentUser.privacy_no_ads = noAds ? 1 : 0;
                    const noTrack = data.privacy.privacy_no_track != 0;
                    document.getElementById('privacyNoTrack').checked = noTrack;
                    if (currentUser) currentUser.privacy_no_track = noTrack ? 1 : 0;
                    applyNoTrack(noTrack);
                }
            } catch (e) {}
        }

        async function savePrivacySetting(key, value) {
            try {
                const fd = new FormData();
                fd.append(key, value ? '1' : '0');
                fd.append('csrf_token', csrfToken);
                const res = await fetch(apiCall('update_privacy'), { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    if (currentUser) currentUser[key] = value ? 1 : 0;
                }
            } catch (e) {}
        }

        window.togglePrivacySearchable = function(el) {
            savePrivacySetting('privacy_searchable', el.checked);
        };

        window.togglePrivacyMessages = function(el) {
            savePrivacySetting('privacy_messages', el.checked);
        };

        window.togglePrivacyBeta = function(el) {
            savePrivacySetting('privacy_beta', el.checked);
            updateBetaUI(el.checked);
            if (!el.checked && window.location.pathname.includes('/messages')) {
                navigate('/');
            }
        };

        function updateBetaUI(enabled) {
            document.getElementById('navMsgBtn').classList.toggle('hidden', !enabled);
            const bottomMsgBtn = document.querySelector('[data-nav="messenger"]');
            if (bottomMsgBtn) bottomMsgBtn.classList.toggle('hidden', !enabled);
        }

        function isBetaUser() {
            return currentUser && currentUser.privacy_beta == 1;
        }

        window.togglePrivacyNoAds = function(el) {
            savePrivacySetting('privacy_no_ads', el.checked);
            if (currentUser) currentUser.privacy_no_ads = el.checked ? 1 : 0;
            updateBannerVisibility();
        };

        function updateBannerVisibility() {
            const banner = document.getElementById('bannerLeft');
            if (!banner) return;
            if (currentUser && currentUser.privacy_no_ads == 1) {
                banner.classList.remove('visible');
            } else {
                banner.classList.add('visible');
            }
        }

        function applyNoTrack(enabled) {
            try {
                if (enabled) {
                    localStorage.setItem('dump_no_track', '1');
                    window.__dumpNoTrack = true;
                    if (window.clarity) window.clarity = function(){};
                    if (window.wireboard) window.wireboard = function(){};
                } else {
                    localStorage.removeItem('dump_no_track');
                    window.__dumpNoTrack = false;
                }
            } catch(e) {}
        }

        window.togglePrivacyNoTrack = function(el) {
            savePrivacySetting('privacy_no_track', el.checked);
            if (currentUser) currentUser.privacy_no_track = el.checked ? 1 : 0;
            applyNoTrack(el.checked);
        };

        /* ─── КНОПКА «НАПИСАТЬ» НА ПРОФИЛЕ ────────── */

        /* This is now handled in openProfileData by adding a "Написать" button when is_followed */

        /* ─── ИНИЦИАЛИЗАЦИЯ ────────────────────────────── */

        let _originalSwitchSettingsTab = window.switchSettingsTab;
        window.switchSettingsTab = switchSettingsTab;

        window.onload = init;
