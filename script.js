        let currentUser = null;
        let csrfToken = '';
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
        let turnstileWidgetId = null;
        let captchaToken = '';

        // Mobile app detection - check for DumpApp in user agent
        const isDumpApp = navigator.userAgent.includes('DumpApp');

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

        function openCaptchaModal() {
            captchaToken = '';
            document.getElementById('captchaWidget').innerHTML = '';
            openModal('captchaModal');
            if (window.__turnstileReady) {
                renderTurnstile();
            } else {
                window.__captchaPending = true;
            }
        }
        function cancelCaptcha() {
            closeModal('captchaModal');
            pendingAuth = null;
            if (turnstileWidgetId !== null) { try { turnstile.remove(turnstileWidgetId); } catch(e) {} turnstileWidgetId = null; }
        }
        function renderTurnstile() {
            if (!window.turnstile || !window.TurnstileSiteKey) return;
            if (turnstileWidgetId !== null) { try { turnstile.remove(turnstileWidgetId); } catch(e) {} }
            document.getElementById('captchaWidget').innerHTML = '';
            turnstileWidgetId = turnstile.render('#captchaWidget', {
                sitekey: window.TurnstileSiteKey,
                theme: 'dark',
                callback: function(token) { captchaToken = token; proceedAfterCaptcha(); },
                'expired-callback': function() { captchaToken = ''; },
                'error-callback': function() { captchaToken = ''; showToast('Не удалось загрузить капчу. Обновите страницу.'); }
            });
        }
        function proceedAfterCaptcha() {
            if (!captchaToken || !pendingAuth) return;
            const action = pendingAuth.action;
            const form = pendingAuth.form;
            pendingAuth = null;
            closeModal('captchaModal');
            if (turnstileWidgetId !== null) { try { turnstile.remove(turnstileWidgetId); } catch(e) {} turnstileWidgetId = null; }
            doAuth(action, form, captchaToken);
        }

        const showToast = (msg) => {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast'; toast.textContent = msg;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => { if(toast.parentNode) toast.remove(); }, 300);
            }, 3000);
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
            const urlRegex = /(https?:\/\/[^\s]+)/g;
            let safeText = text.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer" style="color: #60a5fa; text-decoration: underline; word-break: break-word;" onclick="event.stopPropagation()">$1</a>');
            
            const hashRegex = /(^|\s)(#[a-zA-Zа-яА-ЯёЁ0-9_]+)/g;
            safeText = safeText.replace(hashRegex, '$1<span class="hashtag" onclick="event.stopPropagation(); triggerHashtagSearch(\'$2\')">$2</span>');

            const mentionRegex = /(^|\s)(@[a-zA-Zа-яА-ЯёЁ0-9_]+)/g;
            safeText = safeText.replace(mentionRegex, '$1<span class="mention" onclick="event.stopPropagation(); triggerMentionProfile(\'$2\')">$2</span>');
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

        const timeAgo = (dateStr) => {
            const date = new Date((dateStr + ' UTC').replace(/-/g, '/'));
            const seconds = Math.floor((new Date() - date) / 1000);
            if (seconds < 60) return "Только что";
            let i = Math.floor(seconds / 31536000); if (i >= 1) return `${i} ${getPlural(i, 'год', 'года', 'лет')} назад`;
            i = Math.floor(seconds / 2592000); if (i >= 1) return `${i} ${getPlural(i, 'мес.', 'мес.', 'мес.')} назад`;
            i = Math.floor(seconds / 86400); if (i >= 1) return `${i} ${getPlural(i, 'день', 'дня', 'дней')} назад`;
            i = Math.floor(seconds / 3600); if (i >= 1) return `${i} ${getPlural(i, 'час', 'часа', 'часов')} назад`;
            i = Math.floor(seconds / 60); return `${i} ${getPlural(i, 'мин.', 'мин.', 'мин.')} назад`;
        };

        const maskIp = (ip) => {
            if(!ip || ip === 'Unknown') return 'Скрыт';
            const parts = ip.split('.');
            if(parts.length === 4) return `${parts[0]}.${parts[1]}.***.***`;
            if(ip.includes(':')) return ip.substring(0, 9) + '****:****';
            return '***.***.***.***';
        };

        const resizeTextarea = (el) => { el.style.height = 'auto'; el.style.height = (el.scrollHeight) + 'px'; };

        const switchView = (viewId) => {
            ['loginView', 'registerView', 'feedView', 'profileView', 'notificationsView'].forEach(id => {
                const el = document.getElementById(id);
                if(el) { if (id === viewId) el.classList.add('active'); else el.classList.remove('active'); }
            });
        };

        const updateSeoTitleDynamic = (authorName = null) => {
            if (authorName) {
                document.title = `Публикация от @${authorName} | Dump`;
            } else {
                document.title = Math.random() > 0.5 ? "Dump" : "Настоящий Dump";
            }
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
                stopNotifPolling();
            } else {
                document.getElementById('navUserBtn').onclick = () => navigate('/profile');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-user"></i>';
                document.getElementById('navCreateBtn').classList.remove('hidden');
                document.getElementById('navNotifBtn').classList.remove('hidden');
                startNotifPolling();
            }
            
            if (path.startsWith('/profile') && !isGuest) {
                switchView('profileView');
                if(feedTabs) feedTabs.classList.add('hidden');
                const parts = path.split('/');
                const uid = (parts[parts.length - 1] && parts[parts.length - 1] !== 'profile') ? parseInt(parts[parts.length - 1]) : currentUser.id;
                openProfileData(uid);
                window.scrollTo(0,0);
                updateBottomNav();
            }
            else if (path === '/notifications' && !isGuest) {
                switchView('notificationsView');
                if(feedTabs) feedTabs.classList.add('hidden');
                loadNotifications();
                updateBottomNav();
            }
            else if (path === '/create' && !isGuest) {
                if(feedTabs) feedTabs.classList.add('hidden');
                openModal('createView', 'postContent');
                updateBottomNav();
            } 
            else { 
                switchView('feedView');
                if(feedTabs) feedTabs.classList.remove('hidden');
                if(isGuest && feedTabs) feedTabs.classList.add('hidden'); 
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

        // Рендер описания профиля: ссылки превращаются в кликабельные «таблетки» с фавиконкой.
        function formatBio(bio) {
            if (!bio) return '';
            const urlRegex = /(https?:\/\/[^\s<>"']+)/g;
            let html = bio;
            html = html.replace(urlRegex, (url) => {
                let host;
                try { host = new URL(url).hostname.replace(/^www\./, ''); } catch(e) { host = url; }
                const fav = 'https://www.google.com/s2/favicons?domain=' + encodeURIComponent(host) + '&sz=64';
                return '<a class="bio-link-pill" href="' + url + '" target="_blank" rel="noopener noreferrer nofollow" onclick="event.stopPropagation()">' +
                    '<img class="bio-link-favicon" src="' + fav + '" alt="" onerror="this.style.visibility=\'hidden\'">' +
                    '<span class="bio-link-text">' + host + '</span></a>';
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
                const captcha = document.getElementById('captchaModal');
                if (captcha && captcha.classList.contains('open')) { cancelCaptcha(); return; }
                ['postOptionsModal', 'commentsModal', 'settingsModal', 'cropModal', 'searchModal', 'passwordModal', 'confirmModal', 'textWarningModal', 'tfaSettingsModal', 'tfaLoginModal', 'followingModal'].forEach(id => {
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
        });

        async function handleAuth(e, action) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if (isProcessing) return;
            await __waitForIp();

            // Капча Cloudflare Turnstile показывается в модалке.
            if (typeof TURNSTILE_ENABLED !== 'undefined' && TURNSTILE_ENABLED) {
                pendingAuth = { action, form };
                openCaptchaModal();
                return;
            }
            await doAuth(action, form, '');
        }

        async function doAuth(action, form, turnstileToken) {
            if (isProcessing) return;
            isProcessing = true;

            const fd = new FormData(form);
            fd.append('csrf_token', csrfToken || '');
            if (turnstileToken) fd.append('turnstile_token', turnstileToken);
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
                    } else {
                        form.reset();
                        await init();
                        navigate('/', true);
                    }
                } else showToast(data.error || 'Ошибка');
            } catch (err) { showToast('Ошибка соединения'); }
            finally { btn.textContent = origText; setFormState(form, false); isProcessing = false; }
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

        async function disableTfa() {
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                const res = await fetch(apiCall('tfa_disable'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Двухфакторная аутентификация отключена');
                    document.getElementById('tfaToggle').checked = false;
                    document.getElementById('tfaSetupContainer').classList.add('hidden');
                    updateTfaBadgeStatus(false);
                    currentUser.tfa_enabled = 0;
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
                let isSliderPaused = false;
                const imagesCount = activeSlider.children.length;
                const dotsContainer = activeSlider.nextElementSibling;
                const dots = dotsContainer?.querySelectorAll('.slider-dot');
                const SLIDE_INTERVAL = 2000;
                
                if(dots) {
                    dots.forEach(d => d.classList.remove('active', 'paused'));
                    if(dots[0]) dots[0].classList.add('active');
                }
                let currentSlideIndex = 0;
                let slideTimerStart = performance.now();
                let rafId = null;

                function advanceSlide() {
                    if (isSliderPaused) return;
                    
                    currentSlideIndex = (currentSlideIndex + 1) % imagesCount;
                    activeSlider.style.transform = `translateX(-${currentSlideIndex * 100}%) translateZ(0)`;
                    
                    if(dots) {
                        dots.forEach((d, i) => {
                            d.classList.remove('active', 'paused');
                            if (i === currentSlideIndex) { void d.offsetWidth; d.classList.add('active'); }
                        });
                    }
                }

                function scheduleNext() {
                    slideTimerStart = performance.now();
                    rafId = requestAnimationFrame(function tick(now) {
                        if (isSliderPaused) {
                            slideTimerStart = performance.now();
                            window.postSliderRaf = requestAnimationFrame(tick);
                            return;
                        }
                        if (now - slideTimerStart >= SLIDE_INTERVAL) {
                            advanceSlide();
                            scheduleNext();
                            return;
                        }
                        window.postSliderRaf = requestAnimationFrame(tick);
                    });
                    window.postSliderRaf = rafId;
                }

                const setPause = (state) => {
                    isSliderPaused = state;
                    const activeDot = dotsContainer?.querySelector('.slider-dot.active');
                    if (activeDot) {
                        if (state) activeDot.classList.add('paused');
                        else activeDot.classList.remove('paused');
                    }
                };

                const postWrapper = wrapper.children[index]?.querySelector('.post-wrapper');
                if (postWrapper) {
                    postWrapper.addEventListener('pointerdown', () => setPause(true));
                    postWrapper.addEventListener('pointerup', () => setPause(false));
                    postWrapper.addEventListener('pointercancel', () => setPause(false));
                    postWrapper.addEventListener('pointerleave', () => setPause(false));
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
                
                const postAuthor = wrapper.children[currentFeedIndex]?.querySelector('.author-name')?.textContent;
                updateSeoTitleDynamic(postAuthor);

                if (newPostSlug && window.location.pathname !== BASE_PATH + `/post/${newPostSlug}`) {
                    window.history.replaceState(history.state, '', BASE_PATH + `/post/${newPostSlug}`);
                }
            }, 420);
        }

        async function updateDynamicIp() {
            if (!currentUser) return;
            const lastUpdateKey = 'last_ip_update_' + currentUser.id;
            const lastUpdate = localStorage.getItem(lastUpdateKey);
            const now = Date.now();
            
            if (!lastUpdate || (now - parseInt(lastUpdate)) > 86400000) {
                try {
                    const res = await fetch('https://api.ipify.org?format=json');
                    const data = await res.json();
                    if (data.ip) {
                        const fd = new FormData();
                        fd.append('ip', data.ip);
                        fd.append('csrf_token', csrfToken);
                        await fetch(apiCall('update_ip'), { method: 'POST', body: fd });
                        localStorage.setItem(lastUpdateKey, now.toString());
                    }
                } catch(e) {}
            }
        }

        async function init() {
            try {
                const res = await fetch(apiCall('me'), { cache: 'no-store' });
                if(!res.ok) throw new Error('API Error');
                const data = await res.json();
                csrfToken = data.csrf || '';
                currentUser = data.user || null;
                updateDynamicIp();
            } catch (e) { showToast('Не удалось связаться с сервером'); } 
            finally { handleRoute(); }
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

        function initCrop(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой (макс 20 МБ)'); return; }
            
            const reader = new FileReader();
            reader.onload = (event) => {
                document.getElementById('cropImage').src = event.target.result;
                openModal('cropModal');
                if (cropper) cropper.destroy();
                cropper = new Cropper(document.getElementById('cropImage'), {
                    aspectRatio: 1, viewMode: 1, background: false, autoCropArea: 0.8, responsive: true
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

        async function saveAccount(e) {
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
                const fd = new FormData();
                fd.append('username', document.getElementById('accUsername').value);
                fd.append('email', document.getElementById('accEmail').value);
                fd.append('csrf_token', csrfToken);

                const res = await fetch(apiCall('update_account'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Аккаунт сохранён');
                    await init();
                    if(window.location.pathname.includes('profile')) openProfileData(currentUser.id); 
                } else showToast(data.error || 'Ошибка сохранения');
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
                    actionBtnHTML = `<div class="flex gap-2 items-center"><button onclick="openSettings()" class="vc-btn vc-btn-outline flex items-center justify-center gap-2" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;"><i class="ph ph-gear"></i> Настройки</button><button onclick="navigate('/notifications')" class="vc-btn vc-btn-outline flex items-center justify-center" style="padding: 8px 12px; width:auto; border-radius:99px; font-size:1.1rem; position:relative;" id="profileNotifBtn"><i class="ph ph-bell"></i><span id="profileNotifBadge" class="notif-badge-profile hidden">0</span></button></div>`;
                } else {
                    const isFollowed = p.is_followed > 0;
                    actionBtnHTML = `<button onclick="toggleFollow(${p.id}, this)" class="vc-btn ${isFollowed ? 'vc-btn-outline' : ''}" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;">${isFollowed ? 'Вы подписаны' : 'Подписаться'}</button>`;
                }

                const joinDate = new Date((p.created_at + ' UTC').replace(/-/g, '/'));
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
                item.addEventListener('click', () => {
                    const idx = parseInt(item.dataset.notifIdx);
                    if (window._notifData[idx]) handleNotifClick(window._notifData[idx]);
                });
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
                el.addEventListener('click', () => {
                    const idx = parseInt(el.dataset.notifIdx);
                    if (window._notifData[idx]) handleNotifClick(window._notifData[idx]);
                });
            });
        }

        function attachNotifSwipe(el) {
            let startY = 0, currentY = 0, isSwiping = false;
            const onStart = (e) => {
                startY = e.touches ? e.touches[0].clientY : e.clientY;
                isSwiping = false;
                el.style.transition = 'none';
            };
            const onMove = (e) => {
                currentY = e.touches ? e.touches[0].clientY : e.clientY;
                const diff = currentY - startY;
                if (Math.abs(diff) > 10) isSwiping = true;
                if (diff < 0) {
                    el.style.transform = `translateY(${diff}px)`;
                    el.style.opacity = Math.max(0, 1 + diff / 150);
                }
            };
            const onEnd = () => {
                const diff = currentY - startY;
                el.style.transition = 'transform 0.3s, opacity 0.3s';
                if (diff < -80) {
                    el.style.transform = 'translateY(-100%)';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 300);
                } else {
                    el.style.transform = '';
                    el.style.opacity = '';
                }
                isSwiping = false;
            };
            el.addEventListener('touchstart', onStart, {passive: true});
            el.addEventListener('touchmove', onMove, {passive: true});
            el.addEventListener('touchend', onEnd);
        }

        function updateNotifBadge(count) {
            const badge = document.getElementById('notifBadge');
            const profileBadge = document.getElementById('profileNotifBadge');
            const profileIcon = document.querySelector('[data-nav="profile"] i');
            if (count > 0) {
                if (badge) { badge.textContent = count > 99 ? '99+' : count; badge.classList.remove('hidden'); }
                if (profileBadge) { profileBadge.textContent = count > 99 ? '99+' : count; profileBadge.classList.remove('hidden'); }
                if (profileIcon) profileIcon.classList.add('notif-pulse');
            } else {
                if (badge) badge.classList.add('hidden');
                if (profileBadge) profileBadge.classList.add('hidden');
                if (profileIcon) profileIcon.classList.remove('notif-pulse');
            }
        }

        async function pollUnreadCount() {
            if (!currentUser) return;
            try {
                const res = await fetch(apiCall('get_unread_count') + '&last_id=' + lastKnownNotifId);
                const data = await res.json();
                if (data.success) {
                    updateNotifBadge(data.unread_count);
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
                    contentHtml += `<img src="${getProxyUrl(images[0])}" class="post-img relative z-10" style="object-fit: contain; width: 100%; height: 100%; pointer-events: none;" loading="lazy" onload="this.classList.add('loaded')">`;
                } else {
                    let slidesHtml = '';
                    let dotsHtml = '';
                    images.forEach((img, idx) => {
                        slidesHtml += `<img src="${getProxyUrl(img)}" class="slider-img" loading="lazy">`;
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
                let fontSize = post.content.length < 100 ? '2rem' : (post.content.length < 300 ? '1.4rem' : '1.1rem');
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
        window.onload = init;
