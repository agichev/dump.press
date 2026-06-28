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

        let currentFeedIndex = 0;
        let isScrollingFeed = false;
        let touchStartX = 0;
        let currentX = 0;
        let isDragging = false;
        let wheelTimeout;

        let tfaLoginTempToken = '';
        let tfaLoginMethod = '';
        
        let tfaSetupTempToken = '';

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
            return safeText;
        };

        window.triggerHashtagSearch = (tag) => {
            document.getElementById('searchInput').value = tag;
            openModal('searchModal', 'searchInput');
            performSearch();
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
            ['loginView', 'registerView', 'feedView', 'profileView'].forEach(id => {
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

            if (isGuest && !isPostRoute && path !== '/' && path !== '/login' && path !== '/register') {
                navigate('/login', true);
                return;
            }

            if (isGuest && path === '/login') { switchView('loginView'); if(nav) nav.classList.remove('visible'); return; }
            if (isGuest && path === '/register') { switchView('registerView'); if(nav) nav.classList.remove('visible'); return; }

            if(nav) nav.classList.add('visible');
            
            if (isGuest) {
                document.getElementById('navUserBtn').onclick = () => navigate('/login');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-sign-in"></i>';
                document.getElementById('navCreateBtn').classList.add('hidden');
            } else {
                document.getElementById('navUserBtn').onclick = () => navigate('/profile');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-user"></i>';
                document.getElementById('navCreateBtn').classList.remove('hidden');
            }
            
            if (path.startsWith('/profile') && !isGuest) {
                switchView('profileView');
                if(feedTabs) feedTabs.classList.add('hidden');
                const parts = path.split('/');
                const uid = (parts[parts.length - 1] && parts[parts.length - 1] !== 'profile') ? parseInt(parts[parts.length - 1]) : currentUser.id;
                openProfileData(uid);
                window.scrollTo(0,0);
            } 
            else if (path === '/create' && !isGuest) {
                if(feedTabs) feedTabs.classList.add('hidden');
                openModal('createView', 'postContent');
            } 
            else { 
                switchView('feedView');
                if(feedTabs) feedTabs.classList.remove('hidden');
                if(isGuest && feedTabs) feedTabs.classList.add('hidden'); 
                initTabIndicator(); 
                
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

        const closeModalOnOutsideClick = (e, modalId, routeBack = false, isCreate = false) => {
            if (e.target.id === modalId) { 
                if (isCreate) closeCreatePost();
                else if (routeBack) navigate('/'); 
                else closeModal(modalId); 
            }
        };

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                ['postOptionsModal', 'commentsModal', 'settingsModal', 'cropModal', 'searchModal', 'passwordModal', 'confirmModal', 'textWarningModal', 'tfaSettingsModal', 'tfaLoginModal'].forEach(id => {
                    const m = document.getElementById(id);
                    if(m && m.classList.contains('open')) closeModal(id);
                });
                const create = document.getElementById('createView');
                if(create && create.classList.contains('open')) closeCreatePost();
            }
        });

        async function handleAuth(e, action) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;

            if (isProcessing) return;
            isProcessing = true;
            
            const fd = new FormData(form);
            fd.append('csrf_token', csrfToken || '');
            
            const btn = setFormState(form, true);
            const origText = btn.textContent;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            
            try {
                const res = await fetch(`?api=${action}`, { method: 'POST', body: fd });
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
            if (!currentUser || !postId) return;
            const fd = new FormData(); 
            fd.append('post_id', postId); 
            fd.append('csrf_token', csrfToken);
            fetch(apiCall('mark_seen'), { method: 'POST', body: fd, keepalive: true }).catch(()=>{});
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

        function handleSwipeStart(e) {
            if (!feedViewEl.classList.contains('active') || isScrollingFeed) return;
            
            if (e.target.closest('button') || e.target.closest('.action-btn') || e.target.closest('.scrollable-overlay') || e.target.closest('.post-author') || e.target.closest('a') || e.target.closest('.hashtag')) return;
            
            isDragging = true;
            startX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
            currentX = startX;
            
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transition = 'none';
        }

        function handleSwipeMove(e) {
            if (!isDragging) return;
            currentX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
            const diff = currentX - startX;
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transform = `translateX(calc(-${currentFeedIndex * 100}vw + ${diff}px))`;
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
        }

        feedViewEl.addEventListener('touchstart', handleSwipeStart, {passive: true});
        feedViewEl.addEventListener('touchmove', handleSwipeMove, {passive: true});
        feedViewEl.addEventListener('touchend', handleSwipeEnd);
        
        feedViewEl.addEventListener('mousedown', handleSwipeStart);
        feedViewEl.addEventListener('mousemove', handleSwipeMove);
        feedViewEl.addEventListener('mouseup', handleSwipeEnd);
        feedViewEl.addEventListener('mouseleave', handleSwipeEnd);

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
                
                if (window.postSliderInterval) { clearInterval(window.postSliderInterval); }
                const activeSlider = wrapper.children[currentFeedIndex]?.querySelector('.image-slider');
                if (activeSlider && activeSlider.children.length > 1) {
                    let isSliderPaused = false;
                    const imagesCount = activeSlider.children.length;
                    const dotsContainer = activeSlider.nextElementSibling;
                    const dots = dotsContainer?.querySelectorAll('.slider-dot');
                    
                    if(dots) {
                        dots.forEach(d => { d.classList.remove('active', 'paused'); void d.offsetWidth; });
                        if(dots[0]) dots[0].classList.add('active');
                    }
                    activeSlider.style.transform = `translateX(0%)`; 
                    let currentSlideIndex = 0;

                    window.autoSlideLogic = () => {
                        if (isSliderPaused) return;
                        
                        currentSlideIndex = (currentSlideIndex + 1) % imagesCount;
                        activeSlider.style.transform = `translateX(-${currentSlideIndex * 100}%)`;
                        
                        if(dots) {
                            dots.forEach((d, i) => {
                                d.classList.remove('active', 'paused');
                                if (i === currentSlideIndex) { void d.offsetWidth; d.classList.add('active'); }
                            });
                        }
                    };

                    window.postSliderInterval = setInterval(window.autoSlideLogic, 2000);

                    const setPause = (state) => {
                        isSliderPaused = state;
                        const activeDot = dotsContainer?.querySelector('.slider-dot.active');
                        if (activeDot) {
                            if (state) activeDot.classList.add('paused');
                            else activeDot.classList.remove('paused');
                        }
                    };

                    const postWrapper = wrapper.children[currentFeedIndex]?.querySelector('.post-wrapper');
                    if (postWrapper) {
                        postWrapper.addEventListener('pointerdown', () => setPause(true));
                        postWrapper.addEventListener('pointerup', () => setPause(false));
                        postWrapper.addEventListener('pointercancel', () => setPause(false));
                        postWrapper.addEventListener('pointerleave', () => setPause(false));
                    }
                }
                
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
            document.getElementById('feedView').innerHTML = ''; 
            closeModal('settingsModal');
            navigate('/login', true);
        }

        async function uploadToImgBB(file) {
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
            document.getElementById('settingsAvatarPreview').src = currentUser.avatar_url || `https://ui-avatars.com/api/?name=${currentUser.username}&background=random`;
            document.getElementById('settingsBio').value = currentUser.bio || '';
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
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой (макс 5 МБ)'); return; }
            
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
                        if(s.user_agent.includes('Windows')) device = "Windows";
                        else if(s.user_agent.includes('Mac OS')) device = "MacOS";
                        else if(s.user_agent.includes('Android')) device = "Android";
                        else if(s.user_agent.includes('iPhone') || s.user_agent.includes('iPad')) device = "iOS";
                        
                        let browser = "";
                        if(s.user_agent.includes('Chrome')) browser = "Chrome";
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
                            <img src="${u.avatar_url || 'https://ui-avatars.com/api/?name='+u.username+'&background=random'}" class="search-result-img">
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
                const avatarUrl = p.avatar_url || `https://ui-avatars.com/api/?name=${p.username}&background=random`;
                
                window.currentProfilePosts = data.posts || [];
                window.currentProfileBookmarks = data.bookmarks || [];
                
                let actionBtnHTML = '';
                if (isMe) {
                    actionBtnHTML = `<button onclick="openSettings()" class="vc-btn vc-btn-outline flex items-center justify-center gap-2" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;"><i class="ph ph-gear"></i> Настройки</button>`;
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

                if (isMe) {
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
                            <p class="text-muted" style="margin: 6px 0 10px; font-size: 0.95rem; line-height: 1.4; word-break: break-word;">${p.bio || 'Нет информации.'}</p>
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 6px 12px; border-radius: 99px; background-color: var(--surface-hover); color: var(--text-muted); font-size: 0.8rem; font-weight: 500; margin-bottom: 1.5rem;"><i class="ph ph-calendar-blank"></i> ${joinStr}</div>
                            <div class="mb-4">${actionBtnHTML}</div>
                            <div class="profile-stats">
                                <div class="stat-item"><div class="stat-val">${p.posts_count}</div><div class="stat-lbl">Посты</div></div>
                                <div class="stat-item"><div class="stat-val" id="statFollowers">${p.followers_count}</div><div class="stat-lbl">Подписчики</div></div>
                                <div class="stat-item"><div class="stat-val">${p.following_count}</div><div class="stat-lbl">Подписки</div></div>
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

        function processPostImageFiles(files) {
            if (!files.length) return;
            
            let validFiles = [];
            const signatures = new Set();

            for(let f of files) {
                if (f.size > MAX_FILE_SIZE) { showToast(`Файл ${f.name} слишком большой`); continue; }
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

        async function loadFeed() {
            const container = document.getElementById('feedView');
            container.innerHTML = `<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 3.5rem; color: var(--text-muted);"></i></div>`;
            
            let currentSlug = '';
            const path = window.location.pathname;
            if (path.includes('/post/')) {
                currentSlug = path.split('/').pop();
            }
            
            try {
                const res = await fetch(apiCall('posts') + `&type=${activeFeedType}&slug=${currentSlug}`);
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
            const avatar = post.avatar_url || `https://ui-avatars.com/api/?name=${post.username}&background=random`;
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
                        dotsHtml += `<div class="slider-dot ${idx===0 ? 'active':''}"></div>`;
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
                div.innerHTML = `<img src="${c.avatar_url || 'https://ui-avatars.com/api/?name='+c.username+'&background=random'}"><div class="fc-text"><span class="fc-name">${c.username}</span><span class="fc-msg">${c.content}</span></div>`;
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
                <img src="${c.avatar_url || 'https://ui-avatars.com/api/?name='+c.username+'&background=random'}" onclick="navigate('/profile/${c.user_id}'); closeModal('commentsModal');">
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
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой. Максимум 5 МБ.'); e.target.value = ''; return; }
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
