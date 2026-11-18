/**
 * Xanh Hub v1.1 — Hệ thống Giao diện Điều hành & Scrollytelling Chuẩn Thiết Kế
 * - Kiến trúc Sticky Side-by-Side Scrollytelling (Text không bao giờ đè lên đồ thị)
 * - Mô phỏng Đồ thị Lưỡng phân 30 TNV × 12 Ca trực + Hoán đổi Kuhn Linh & Phúc
 * - Bảng Kéo thả Ca trực (CN4) có dự báo tương thích Xanh/Đỏ trước khi thả
 * - Máy chiếu & Trạm kiểm thử QR HMAC 30s xoay vòng trực tiếp
 */
(function () {
    'use strict';

    const normalizeVi = (str) => String(str || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim();

    const escapeHtml = (str) => String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const modalCloseHooks = [];

    // =========================================================================
    // 1. QUẢN LÝ TOAST THÔNG BÁO & HỘP THOẠI MODAL TÙY BIẾN (KHÔNG DÙNG ALERT)
    // =========================================================================
    const XanhHubUI = {
        toast(type, title, message, reasonCodes = []) {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                document.body.appendChild(container);
            }

            const item = document.createElement('div');
            item.className = `toast-item toast-${type || 'info'}`;

            const badgesHtml = (reasonCodes || [])
                .map(c => `<span class="badge ${type === 'error' ? 'badge-violation' : 'badge-warning'} mono" style="margin-right:4px;">${c}</span>`)
                .join('');

            item.innerHTML = `
                <div style="flex:1;">
                    <div style="font-weight:800; font-size:0.88rem; margin-bottom:0.2rem; display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                        <span>${title}</span>
                        ${badgesHtml}
                    </div>
                    <div style="font-size:0.82rem; color:var(--text-secondary); line-height:1.45;">${message}</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline" style="padding:0.15rem 0.45rem; border:none;" aria-label="Đóng thông báo">✕</button>
            `;

            item.querySelector('button').addEventListener('click', () => item.remove());
            container.appendChild(item);

            setTimeout(() => {
                if (item.parentNode) {
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(20px)';
                    item.style.transition = 'all 200ms ease';
                    setTimeout(() => item.remove(), 200);
                }
            }, 5200);
        },

        openModal(modalId) {
            const el = document.getElementById(modalId);
            if (el) {
                el.classList.add('open');
                const firstInput = el.querySelector('input:not([type="hidden"]), textarea, select, button.btn-primary');
                if (firstInput) setTimeout(() => firstInput.focus(), 60);
            }
        },

        closeModal(modalId) {
            const el = document.getElementById(modalId);
            if (el) el.classList.remove('open');
            modalCloseHooks.forEach(fn => {
                try { fn(modalId); } catch (err) {}
            });
        }
    };

    window.XanhHubUI = XanhHubUI;

    document.addEventListener('click', (e) => {
        const openBtn = e.target.closest('[data-open-modal]');
        if (openBtn) {
            XanhHubUI.openModal(openBtn.dataset.openModal);
        }
        const closeBtn = e.target.closest('[data-close-modal]');
        if (closeBtn) {
            XanhHubUI.closeModal(closeBtn.dataset.closeModal);
        }
        if (e.target.classList && e.target.classList.contains('modal-backdrop') && e.target.id) {
            XanhHubUI.closeModal(e.target.id);
        }
    });

    // Quản lý Menu Mini Nổi 1 Lớp (Floating Workspace Drawer — Volunteer & Coordinator)
    const workspaceFloatingDrawer = document.getElementById('workspace-floating-drawer');
    const workspaceDrawerBackdrop = document.getElementById('workspace-drawer-backdrop');
    const closeWorkspaceDrawer = () => {
        if (workspaceFloatingDrawer) workspaceFloatingDrawer.classList.remove('open');
        if (workspaceDrawerBackdrop) workspaceDrawerBackdrop.classList.remove('open');
    };
    const toggleWorkspaceDrawer = () => {
        if (!workspaceFloatingDrawer) return;
        const isOpen = workspaceFloatingDrawer.classList.toggle('open');
        if (workspaceDrawerBackdrop) workspaceDrawerBackdrop.classList.toggle('open', isOpen);
    };
    document.querySelectorAll('.btn-toggle-workspace-drawer').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleWorkspaceDrawer();
        });
    });
    const btnCloseWorkspaceDrawer = document.getElementById('btn-close-workspace-drawer');
    if (btnCloseWorkspaceDrawer) {
        btnCloseWorkspaceDrawer.addEventListener('click', closeWorkspaceDrawer);
    }
    if (workspaceDrawerBackdrop) {
        workspaceDrawerBackdrop.addEventListener('click', closeWorkspaceDrawer);
    }

    // Chuyển Tab vùng làm việc mượt mà (Volunteer & Coordinator Sidebars + Top Quick Bar)
    const tabButtons = document.querySelectorAll('[data-workspace-tab]');
    if (tabButtons.length > 0) {
        const urlParams = new URLSearchParams(window.location.search);
        const viewKey = urlParams.get('view') || 'default';
        const urlVTab = urlParams.get('vtab');
        const serverActivePane = document.querySelector('.workspace-tab-pane.active');
        const serverTabId = (serverActivePane && serverActivePane.id && serverActivePane.id !== 'vtab-my-shifts')
            ? serverActivePane.id
            : null;
        const storageKey = 'xanhhub_active_tab_' + viewKey;
        const savedTab = urlVTab || serverTabId || sessionStorage.getItem(storageKey);

        const activateTab = (tabId, isUserClick = false) => {
            const targetPane = document.getElementById(tabId);
            if (!targetPane) return;
            tabButtons.forEach(btn => {
                const isActive = btn.dataset.workspaceTab === tabId;
                btn.classList.toggle('active', isActive);
                btn.classList.toggle('bg-blue-50', isActive);
                btn.classList.toggle('dark:bg-sky-950/60', isActive);
                btn.classList.toggle('text-[#0057B8]', isActive);
                btn.classList.toggle('dark:text-sky-400', isActive);
                btn.classList.toggle('text-slate-600', !isActive);
                btn.classList.toggle('dark:text-slate-300', !isActive);
            });
            document.querySelectorAll('.workspace-tab-pane').forEach(pane => {
                pane.classList.toggle('active', pane.id === tabId);
            });
            sessionStorage.setItem(storageKey, tabId);
            if (isUserClick) {
                try {
                    const nextUrl = new URL(window.location.href);
                    nextUrl.searchParams.set('vtab', tabId);
                    history.replaceState(null, '', nextUrl.toString());
                } catch (e) {}
            }
        };

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                activateTab(btn.dataset.workspaceTab, true);
                closeWorkspaceDrawer();
            });
        });

        if (savedTab && document.getElementById(savedTab)) {
            activateTab(savedTab);
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeWorkspaceDrawer();
            document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
            const searchDrop = document.getElementById('search-live-dropdown');
            if (searchDrop) searchDrop.classList.add('hidden');
            const drawer = document.getElementById('mobile-nav-drawer');
            if (drawer) {
                drawer.classList.add('hidden');
                const btnM = document.getElementById('btn-mobile-menu');
                if (btnM) btnM.setAttribute('aria-expanded', 'false');
            }
            document.querySelectorAll('.nav-mega-dropdown').forEach(d => {
                d.classList.remove('flex');
                d.classList.add('hidden');
            });
            const cinemaPlayer = document.getElementById('modal-cinema-player');
            if (cinemaPlayer && !cinemaPlayer.paused) cinemaPlayer.pause();
        }
    });

    // Mobile Menu Drawer Toggle + Click Outside
    const btnMobileMenu = document.getElementById('btn-mobile-menu');
    const mobileNavDrawer = document.getElementById('mobile-nav-drawer');
    if (btnMobileMenu && mobileNavDrawer) {
        btnMobileMenu.addEventListener('click', (e) => {
            e.stopPropagation();
            const isClosed = mobileNavDrawer.classList.toggle('hidden');
            btnMobileMenu.setAttribute('aria-expanded', isClosed ? 'false' : 'true');
        });

        document.addEventListener('click', (e) => {
            if (!mobileNavDrawer.classList.contains('hidden')) {
                if (!mobileNavDrawer.contains(e.target) && !btnMobileMenu.contains(e.target)) {
                    mobileNavDrawer.classList.add('hidden');
                    btnMobileMenu.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    // Desktop Mega Menu Dropdowns (Fixed Positioning against overflow-x-auto container)
    const megaNavGroups = document.querySelectorAll('nav[aria-label="Danh mục chiến dịch"] .relative.group');
    const closeAllMegaDropdowns = () => {
        document.querySelectorAll('.nav-mega-dropdown').forEach(d => {
            d.classList.remove('flex');
            d.classList.add('hidden');
        });
    };

    megaNavGroups.forEach(group => {
        const dropdown = group.querySelector('.nav-mega-dropdown');
        if (!dropdown) return;

        const openDropdown = () => {
            const rect = group.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.top = `${rect.bottom}px`;
            const dropWidth = dropdown.offsetWidth || 280;
            let left = rect.left;
            if (left + dropWidth > window.innerWidth - 8) {
                left = Math.max(8, window.innerWidth - dropWidth - 8);
            }
            dropdown.style.left = `${left}px`;
            dropdown.style.zIndex = '60';
            dropdown.classList.remove('hidden');
            dropdown.classList.add('flex');
        };

        const hideDropdown = () => {
            dropdown.classList.remove('flex');
            dropdown.classList.add('hidden');
        };

        group.addEventListener('mouseenter', openDropdown);
        group.addEventListener('mouseleave', hideDropdown);
        group.addEventListener('focusin', openDropdown);
        group.addEventListener('focusout', (e) => {
            if (!group.contains(e.relatedTarget)) {
                hideDropdown();
            }
        });
    });

    const megaNavScroll = document.querySelector('nav[aria-label="Danh mục chiến dịch"] > div');
    if (megaNavScroll) {
        megaNavScroll.addEventListener('scroll', closeAllMegaDropdowns, { passive: true });
    }
    window.addEventListener('scroll', closeAllMegaDropdowns, { passive: true });
    window.addEventListener('resize', closeAllMegaDropdowns, { passive: true });

    // =========================================================================
    // 2. CHẾ ĐỘ SÁNG / TỐI (THEME SWITCHER CHUẨN TECHPRO-MAIN)
    // =========================================================================
    const savedTheme = localStorage.getItem('xanhhub_theme') || 'light';
    const applyThemeState = (t) => {
        document.documentElement.setAttribute('data-theme', t);
        document.documentElement.classList.toggle('dark', t === 'dark');
        const iconEl = document.getElementById('theme-icon-symbol') || document.getElementById('theme-btn-icon');
        if (iconEl) {
            iconEl.textContent = t === 'dark' ? 'light_mode' : 'dark_mode';
        }
    };
    applyThemeState(savedTheme);

    const themeBtn = document.getElementById('btn-toggle-theme');
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const cur = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            const next = cur === 'dark' ? 'light' : 'dark';
            localStorage.setItem('xanhhub_theme', next);
            applyThemeState(next);
        });
    }

    // =========================================================================
    // 2A. HERO CAROUSEL SLIDER, PARTICLES CANVAS & CINEMA VIDEO CHAPTERS
    // =========================================================================
    const heroSlides = document.querySelectorAll('#hero-slider .hero-slide');
    const heroCaptions = document.querySelectorAll('#hero-slider .hero-slide-caption');
    const heroDots = document.querySelectorAll('#hero-slider .hero-dot');
    const heroPrev = document.getElementById('hero-prev');
    const heroNext = document.getElementById('hero-next');

    if (heroSlides.length > 1) {
        let currentSlide = 0;
        const setHeroSlide = (idx) => {
            currentSlide = (idx + heroSlides.length) % heroSlides.length;
            heroSlides.forEach((sl, i) => {
                const active = i === currentSlide;
                sl.classList.toggle('opacity-100', active);
                sl.classList.toggle('z-10', active);
                sl.classList.toggle('opacity-0', !active);
                sl.classList.toggle('z-0', !active);
                sl.classList.toggle('pointer-events-none', !active);
            });
            heroCaptions.forEach((cap, i) => {
                cap.classList.toggle('hidden', i !== currentSlide);
            });
            heroDots.forEach((dot, i) => {
                const active = i === currentSlide;
                dot.className = active
                    ? 'hero-dot h-2.5 w-8 rounded-full bg-sky-400 transition-all duration-300'
                    : 'hero-dot h-2.5 w-2.5 rounded-full bg-white/40 hover:bg-white/70 transition-all duration-300';
            });
        };

        heroDots.forEach((dot, idx) => {
            dot.addEventListener('click', () => setHeroSlide(idx));
        });
        if (heroPrev) heroPrev.addEventListener('click', () => setHeroSlide(currentSlide - 1));
        if (heroNext) heroNext.addEventListener('click', () => setHeroSlide(currentSlide + 1));

        setInterval(() => {
            setHeroSlide(currentSlide + 1);
        }, 6500);
    }

    // Golden-Hour Sunlight Bokeh Particles Canvas over Hero
    const heroCanvas = document.getElementById('hero-particles-canvas');
    if (heroCanvas) {
        const pCtx = heroCanvas.getContext('2d');
        let cw = 1200, ch = 560;
        const resizeParticles = () => {
            const rect = heroCanvas.parentElement.getBoundingClientRect();
            cw = Math.max(320, Math.floor(rect.width));
            ch = Math.max(320, Math.floor(rect.height));
            heroCanvas.width = cw;
            heroCanvas.height = ch;
        };
        resizeParticles();
        window.addEventListener('resize', resizeParticles);

        const particles = Array.from({ length: 34 }, (_, i) => ({
            x: (i * 97) % cw,
            y: (i * 53) % ch,
            r: 2 + (i % 5) * 1.4,
            vx: -0.25 + (i % 7) * 0.09,
            vy: -0.45 - (i % 4) * 0.12,
            alpha: 0.14 + (i % 5) * 0.06,
            warm: i % 2 === 0
        }));

        const renderParticles = () => {
            pCtx.clearRect(0, 0, cw, ch);
            particles.forEach(p => {
                p.x = (p.x + p.vx + cw) % cw;
                p.y = (p.y + p.vy + ch) % ch;
                pCtx.beginPath();
                pCtx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                pCtx.fillStyle = p.warm
                    ? `rgba(253, 224, 71, ${p.alpha})`
                    : `rgba(56, 189, 248, ${p.alpha})`;
                pCtx.fill();
            });
            requestAnimationFrame(renderParticles);
        };
        renderParticles();
    }

    // Interactive Video Chapters on Homepage Documentary Player
    const homeCinemaVideo = document.getElementById('home-cinema-video');
    const chapterBtns = document.querySelectorAll('.video-chapter-btn[data-video-seek]');
    const currentChapterLabel = document.getElementById('cinema-current-chapter');
    if (homeCinemaVideo && chapterBtns.length > 0) {
        chapterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const sec = parseFloat(btn.dataset.videoSeek || '0');
                const title = btn.dataset.chapterTitle || '';
                homeCinemaVideo.currentTime = sec;
                homeCinemaVideo.play().catch(() => {});
                if (currentChapterLabel && title) {
                    currentChapterLabel.textContent = title;
                }
                chapterBtns.forEach(b => {
                    const active = b === btn;
                    b.className = active
                        ? 'video-chapter-btn text-left p-4 rounded-2xl border transition-all flex items-start gap-3.5 bg-blue-600/20 border-sky-400/60'
                        : 'video-chapter-btn text-left p-4 rounded-2xl border transition-all flex items-start gap-3.5 bg-white/5 border-white/10 hover:bg-white/10 hover:border-white/25';
                });
            });
        });
    }

    // Countdown Timer
    const cdHours = document.getElementById('cd-hours');
    const cdMinutes = document.getElementById('cd-minutes');
    const cdSeconds = document.getElementById('cd-seconds');
    if (cdHours && cdMinutes && cdSeconds) {
        let h = 4, m = 38, s = 52;
        setInterval(() => {
            if (s > 0) {
                s--;
            } else {
                s = 59;
                if (m > 0) {
                    m--;
                } else {
                    m = 59;
                    h = h > 0 ? h - 1 : 4;
                }
            }
            cdHours.textContent = String(h).padStart(2, '0');
            cdMinutes.textContent = String(m).padStart(2, '0');
            cdSeconds.textContent = String(s).padStart(2, '0');
        }, 1000);
    }

    // =========================================================================
    // 2B. BỘ LỌC CA TRỰC, HEADER LIVE DB SEARCH, TRANG CAMPAIGNS / NEWS / LEADERBOARD
    // =========================================================================
    const homeSkillBtns = document.querySelectorAll('[data-home-skill-filter]');
    const homeShiftCards = document.querySelectorAll('.open-shift-card[data-shift-skill]');
    const headerSearchInput = document.getElementById('header-search-input');
    const searchDropdown = document.getElementById('search-live-dropdown');
    const searchResultsBox = document.getElementById('search-live-results');

    function applyHomeShiftSkillFilter(skillCode, searchText = '') {
        const target = skillCode || 'ALL';
        const q = normalizeVi(searchText);

        document.querySelectorAll('.home-tab-btn[data-home-skill-filter]').forEach(btn => {
            const active = btn.dataset.homeSkillFilter === target;
            btn.className = active
                ? 'home-tab-btn px-5 py-2.5 font-bold text-sm text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8] transition-all'
                : 'home-tab-btn px-5 py-2.5 font-bold text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white border-b-2 border-transparent transition-all';
        });

        homeShiftCards.forEach(card => {
            const sk = card.dataset.shiftSkill || '';
            const text = normalizeVi(card.dataset.shiftSearch || card.textContent || '');
            const matchSkill = (target === 'ALL' || sk === target);
            const matchText = (!q || text.includes(q));
            card.style.display = (matchSkill && matchText) ? '' : 'none';
        });
    }

    homeSkillBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const sk = btn.dataset.homeSkillFilter;
            applyHomeShiftSkillFilter(sk, headerSearchInput ? headerSearchInput.value : '');
            if (!btn.classList.contains('home-tab-btn')) {
                const sectionEl = document.getElementById('danh-sach-ca-truc');
                if (sectionEl) sectionEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    if (headerSearchInput && searchDropdown && searchResultsBox) {
        let searchDebounce = null;
        let searchRequestSeq = 0;

        const renderLiveSearchItems = (items, queryStr) => {
            if (!Array.isArray(items) || items.length === 0) {
                searchResultsBox.innerHTML = `
                    <div class="px-4 py-5 text-center text-xs text-slate-500">
                        Không tìm thấy dữ liệu khớp với "<strong class="text-slate-800 dark:text-slate-200">${escapeHtml(queryStr)}</strong>" trong CSDL PostgreSQL.
                    </div>`;
                searchDropdown.classList.remove('hidden');
                return;
            }

            searchResultsBox.innerHTML = items.map(item => `
                <a href="${escapeHtml(item.url || '?view=campaigns')}" class="flex items-center justify-between px-3 py-2.5 rounded-xl hover:bg-blue-50 dark:hover:bg-slate-800 transition-colors gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="material-symbols-outlined text-[#0057B8] dark:text-sky-400 text-[20px] shrink-0">${escapeHtml(item.icon || 'search')}</span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-[10px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-[#0057B8] dark:text-sky-400 shrink-0">${escapeHtml(item.category || 'Dữ liệu')}</span>
                                <span class="font-bold text-xs text-slate-900 dark:text-white truncate">${escapeHtml(item.title || '')}</span>
                            </div>
                            <div class="text-[11px] text-slate-500 truncate">${escapeHtml(item.sub || '')}</div>
                        </div>
                    </div>
                    <span class="material-symbols-outlined text-slate-400 text-[16px] shrink-0">north_east</span>
                </a>
            `).join('');
            searchDropdown.classList.remove('hidden');
        };

        const fetchGlobalSearch = (q) => {
            const seq = ++searchRequestSeq;
            fetch(`?action=api_global_search&q=${encodeURIComponent(q || '')}`)
                .then(r => r.json())
                .then(res => {
                    if (seq !== searchRequestSeq) return;
                    if (res && res.ok) {
                        renderLiveSearchItems(res.results || [], q || '');
                    }
                })
                .catch(() => {});
        };

        headerSearchInput.addEventListener('focus', () => {
            fetchGlobalSearch(headerSearchInput.value.trim());
        });

        headerSearchInput.addEventListener('input', () => {
            const val = headerSearchInput.value.trim();
            if (homeShiftCards.length > 0) {
                applyHomeShiftSkillFilter('ALL', val);
            }
            if (searchDebounce) clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => fetchGlobalSearch(val), 140);
        });

        headerSearchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const firstLink = searchResultsBox.querySelector('a[href]');
                if (firstLink) {
                    e.preventDefault();
                    window.location.href = firstLink.getAttribute('href');
                }
            }
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#header-smart-search') && !e.target.closest('#search-live-dropdown')) {
                searchDropdown.classList.add('hidden');
            }
        });
    }

    // Bộ lọc trên trang ?view=campaigns (Hỗ trợ tìm kiếm tiếng Việt không dấu)
    const campShiftItems = document.querySelectorAll('.camp-shift-item');
    if (campShiftItems.length > 0) {
        let activeCampSkill = new URLSearchParams(window.location.search).get('skill') || 'ALL';
        let activeCampSession = 'ALL';
        const campSearchInput = document.getElementById('camp-filter-search');
        const campCountEl = document.getElementById('camp-visible-count');
        const campContainer = document.getElementById('camp-shifts-container');
        const btnGrid = document.getElementById('btn-camp-grid');
        const btnList = document.getElementById('btn-camp-list');

        const filterCampCatalog = () => {
            const q = normalizeVi(campSearchInput?.value || '');
            let visible = 0;
            campShiftItems.forEach(item => {
                const sk = item.dataset.skill || '';
                const sess = item.dataset.session || '';
                const txt = normalizeVi((item.dataset.search || '') + ' ' + (item.textContent || ''));
                const okSkill = (activeCampSkill === 'ALL' || sk === activeCampSkill);
                const okSess = (activeCampSession === 'ALL' || sess === activeCampSession);
                const okTxt = (!q || txt.includes(q));
                const show = okSkill && okSess && okTxt;
                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (campCountEl) campCountEl.textContent = String(visible);

            const emptyStateEl = document.getElementById('camp-empty-state');
            if (emptyStateEl) {
                emptyStateEl.classList.toggle('hidden', visible > 0);
            }
        };

        const btnResetFilters = document.getElementById('btn-reset-filters');
        if (btnResetFilters) {
            btnResetFilters.addEventListener('click', () => {
                if (campSearchInput) campSearchInput.value = '';
                const allSkillBtn = document.querySelector('.camp-skill-btn[data-camp-skill="ALL"]');
                if (allSkillBtn) allSkillBtn.click();
                const allSessBtn = document.querySelector('.camp-session-btn[data-camp-session="ALL"]');
                if (allSessBtn) allSessBtn.click();
            });
        }

        document.querySelectorAll('.camp-skill-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                activeCampSkill = btn.dataset.campSkill || 'ALL';
                document.querySelectorAll('.camp-skill-btn').forEach(b => {
                    const act = b.dataset.campSkill === activeCampSkill;
                    b.classList.toggle('bg-blue-50', act);
                    b.classList.toggle('dark:bg-sky-950/60', act);
                    b.classList.toggle('text-[#0057B8]', act);
                    b.classList.toggle('dark:text-sky-400', act);
                });
                filterCampCatalog();
            });
        });

        document.querySelectorAll('.camp-session-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                activeCampSession = btn.dataset.campSession || 'ALL';
                document.querySelectorAll('.camp-session-btn').forEach(b => {
                    const act = b.dataset.campSession === activeCampSession;
                    b.className = act
                        ? 'camp-session-btn py-2 rounded-lg font-bold border border-slate-200 dark:border-slate-700 bg-[#0057B8] text-white'
                        : 'camp-session-btn py-2 rounded-lg font-bold border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300';
                });
                filterCampCatalog();
            });
        });

        if (campSearchInput) campSearchInput.addEventListener('input', filterCampCatalog);
        if (btnGrid && btnList && campContainer) {
            btnGrid.addEventListener('click', () => {
                campContainer.className = 'grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5';
                btnGrid.className = 'w-9 h-9 rounded-lg bg-[#0057B8] text-white flex items-center justify-center';
                btnList.className = 'w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center';
            });
            btnList.addEventListener('click', () => {
                campContainer.className = 'grid grid-cols-1 gap-4';
                btnList.className = 'w-9 h-9 rounded-lg bg-[#0057B8] text-white flex items-center justify-center';
                btnGrid.className = 'w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center';
            });
        }
        filterCampCatalog();
    }

    // Tìm kiếm & đọc chi tiết Bản tin từ PostgreSQL (?view=news)
    const newsGrid = document.getElementById('news-articles-grid');
    if (newsGrid) {
        let activeNewsTag = 'ALL';
        const newsSearchInput = document.getElementById('news-search-input');
        const newsCards = document.querySelectorAll('.news-article-card');
        const newsEmpty = document.getElementById('news-empty-state');

        const filterNewsArticles = () => {
            const q = normalizeVi(newsSearchInput ? newsSearchInput.value : '');
            let visible = 0;
            newsCards.forEach(card => {
                const tag = card.dataset.newsTag || '';
                const text = normalizeVi((card.dataset.newsSearch || '') + ' ' + (card.textContent || ''));
                const okTag = (activeNewsTag === 'ALL' || tag === activeNewsTag);
                const okQ = (!q || text.includes(q));
                const show = okTag && okQ;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            if (newsEmpty) newsEmpty.classList.toggle('hidden', visible > 0);
        };

        document.querySelectorAll('[data-news-cat-filter]').forEach(btn => {
            btn.addEventListener('click', () => {
                activeNewsTag = btn.dataset.newsCatFilter || 'ALL';
                document.querySelectorAll('[data-news-cat-filter]').forEach(b => {
                    const act = b.dataset.newsCatFilter === activeNewsTag;
                    b.className = act
                        ? 'news-cat-btn px-3 py-1.5 rounded-lg text-xs font-bold bg-[#0057B8] text-white transition-all'
                        : 'news-cat-btn px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 transition-all';
                });
                filterNewsArticles();
            });
        });

        if (newsSearchInput) {
            newsSearchInput.addEventListener('input', filterNewsArticles);
        }

        const openNewsReader = (btn) => {
            const setTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || ''; };
            setTxt('news-reader-cat', btn.dataset.newsCategory || 'Bản tin');
            setTxt('news-reader-date', btn.dataset.newsDate || '');
            setTxt('news-reader-title', btn.dataset.newsTitle || '');
            setTxt('news-reader-author', 'Tác giả: ' + (btn.dataset.newsAuthor || 'Đội Truyền thông Xanh Hub'));
            setTxt('news-reader-summary', btn.dataset.newsSummary || '');
            setTxt('news-reader-content', btn.dataset.newsContent || btn.dataset.newsSummary || '');
            const imgEl = document.getElementById('news-reader-img');
            if (imgEl) imgEl.src = btn.dataset.newsImg || 'assets/images/campaign-d1.jpg';
            const linkEl = document.getElementById('news-reader-camp-link');
            if (linkEl) linkEl.href = '?view=campaigns&activity_id=' + encodeURIComponent(btn.dataset.newsActId || '3');
            XanhHubUI.openModal('modal-news-reader');
        };

        document.querySelectorAll('.btn-read-news-article').forEach(btn => {
            btn.addEventListener('click', () => openNewsReader(btn));
        });

        filterNewsArticles();
        const autoNewsId = parseInt(newsGrid.dataset.autoNewsId || '0', 10);
        if (autoNewsId > 0) {
            const targetBtn = document.querySelector(`.btn-read-news-article[data-news-id="${autoNewsId}"]`);
            if (targetBtn) openNewsReader(targetBtn);
        }
    }

    // Tìm kiếm Bảng Xếp Hạng Cá Nhân (?view=leaderboard)
    const lbSearchInput = document.getElementById('leaderboard-search-input');
    const lbTable = document.getElementById('leaderboard-personal-table');
    if (lbSearchInput && lbTable) {
        const filterLeaderboardRows = () => {
            const q = normalizeVi(lbSearchInput.value || '');
            lbTable.querySelectorAll('tbody tr').forEach(tr => {
                const txt = normalizeVi((tr.dataset.searchText || '') + ' ' + (tr.textContent || ''));
                tr.style.display = (!q || txt.includes(q)) ? '' : 'none';
            });
        };
        lbSearchInput.addEventListener('input', filterLeaderboardRows);
        if (lbSearchInput.value.trim() !== '') filterLeaderboardRows();
    }

    // Gallery ảnh & video trên trang ?view=shift_detail (ProductDetail.jsx style)
    const galleryThumbs = document.querySelectorAll('.shift-gallery-thumb');
    const detailMainImg = document.getElementById('shift-detail-main-img');
    const detailMainVideo = document.getElementById('shift-detail-main-video');
    if (galleryThumbs.length > 0 && detailMainImg && detailMainVideo) {
        galleryThumbs.forEach(thumb => {
            thumb.addEventListener('click', () => {
                const type = thumb.dataset.galleryType;
                const src = thumb.dataset.gallerySrc;
                if (type === 'video') {
                    detailMainImg.classList.add('hidden');
                    detailMainVideo.classList.remove('hidden');
                    detailMainVideo.play().catch(() => {});
                } else {
                    detailMainVideo.pause();
                    detailMainVideo.classList.add('hidden');
                    detailMainImg.src = src;
                    detailMainImg.classList.remove('hidden');
                }
                galleryThumbs.forEach(t => {
                    t.classList.toggle('border-[#0057B8]', t === thumb);
                    t.classList.toggle('border-transparent', t !== thumb);
                    t.classList.toggle('opacity-75', t !== thumb);
                });
            });
        });
    }

    // Biểu mẫu liên hệ trên trang ?view=about
    const aboutContactForm = document.getElementById('about-contact-form');
    if (aboutContactForm) {
        aboutContactForm.addEventListener('submit', (e) => {
            e.preventDefault();
            XanhHubUI.toast('success', 'Đã gửi tới Ban Chỉ Huy', 'Cảm ơn bạn! Ban Chỉ Huy Chiến dịch Xanh Hub đã ghi nhận thông tin và sẽ phản hồi qua Email trong vòng 24 giờ.');
            aboutContactForm.reset();
        });
    }

    // =========================================================================
    // 2C. SPLIT-SCREEN AUTH TABS, PASSWORD TOGGLE & OAUTH 2.0 SOCIAL LOGIN
    // =========================================================================
    const authTabBtns = document.querySelectorAll('[data-auth-tab]');
    if (authTabBtns.length > 0) {
        authTabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.authTab;
                const paneSignin = document.getElementById('auth-pane-signin');
                const paneRegister = document.getElementById('auth-pane-register');
                if (paneSignin && paneRegister) {
                    paneSignin.classList.toggle('hidden', mode !== 'signin');
                    paneRegister.classList.toggle('hidden', mode !== 'register');
                }
                authTabBtns.forEach(b => {
                    const active = b.dataset.authTab === mode;
                    b.className = active
                        ? 'auth-mode-tab font-bold text-sm pb-3 transition-all duration-300 relative text-[#0057B8] dark:text-sky-400 border-b-2 border-[#0057B8]'
                        : 'auth-mode-tab font-bold text-sm pb-3 transition-all duration-300 relative text-slate-400 hover:text-slate-700 border-b-2 border-transparent';
                });
            });
        });
    }

    // OAuth 2.0 Social Login Trigger (Google, Microsoft Edu .edu.vn, Facebook, Zalo, GitHub)
    const oauthAccountsCatalog = {
        GOOGLE: [
            { email: 'linh.nk.sv001@gmail.com', full_name: 'Nguyễn Khánh Linh (SV001)', role: 'VOLUNTEER', badge: 'Tình nguyện viên', badgeClass: 'badge-valid', avatar: 'assets/images/volunteer-linh.jpg' },
            { email: 'thao.dieuphoi@example.test', full_name: 'Lê Thanh Thảo (Bí thư Đoàn)', role: 'COORDINATOR', badge: 'Điều phối viên', badgeClass: 'badge-info', avatar: 'assets/images/coord-thao.jpg' }
        ],
        MICROSOFT: [
            { email: 'sv001.linh@hcmut.edu.vn', full_name: 'Nguyễn Khánh Linh · ĐH Bách Khoa (.edu.vn)', role: 'VOLUNTEER', badge: 'Sinh viên ĐHQG', badgeClass: 'badge-valid', avatar: 'assets/images/volunteer-linh.jpg' },
            { email: 'dieuphoi.thao@hcmus.edu.vn', full_name: 'Lê Thanh Thảo · Cán bộ Đoàn (.edu.vn)', role: 'COORDINATOR', badge: 'Điều phối viên', badgeClass: 'badge-info', avatar: 'assets/images/coord-thao.jpg' }
        ],
        FACEBOOK: [
            { email: 'linh.sv.fb@facebook.com', full_name: 'Khánh Linh · CLB Tuổi trẻ Xanh', role: 'VOLUNTEER', badge: 'Tình nguyện viên', badgeClass: 'badge-valid', avatar: 'assets/images/volunteer-linh.jpg' },
            { email: 'thao.bithudoan@facebook.com', full_name: 'Thanh Thảo · Ban Chỉ huy Chiến dịch', role: 'COORDINATOR', badge: 'Điều phối viên', badgeClass: 'badge-info', avatar: 'assets/images/coord-thao.jpg' }
        ],
        ZALO: [
            { email: 'linh.nk.zalo@zalo.me', full_name: 'Nguyễn Khánh Linh · Zalo OA ĐVTN', role: 'VOLUNTEER', badge: 'Tình nguyện viên', badgeClass: 'badge-valid', avatar: 'assets/images/volunteer-linh.jpg' },
            { email: 'thao.doan@zalo.me', full_name: 'Lê Thanh Thảo · Zalo Cán bộ Đoàn', role: 'COORDINATOR', badge: 'Điều phối viên', badgeClass: 'badge-info', avatar: 'assets/images/coord-thao.jpg' }
        ],
        GITHUB: [
            { email: 'linhnk-sv001@users.noreply.github.com', full_name: 'linhnk-sv001 · Đội Bình dân Học vụ Số', role: 'VOLUNTEER', badge: 'Kỹ thuật số TNV', badgeClass: 'badge-valid', avatar: 'assets/images/volunteer-linh.jpg' },
            { email: 'thao-techlead@users.noreply.github.com', full_name: 'thao-techlead · Ban Công nghệ Thành Đoàn', role: 'COORDINATOR', badge: 'Điều phối viên', badgeClass: 'badge-info', avatar: 'assets/images/coord-thao.jpg' }
        ]
    };

    const normalizeProviderCatalogKey = (label) => {
        const up = String(label || 'GOOGLE').toUpperCase();
        if (up.includes('EDU') || up.includes('MICROSOFT')) return 'MICROSOFT';
        if (up.includes('ZALO')) return 'ZALO';
        if (up.includes('FACE')) return 'FACEBOOK';
        if (up.includes('GIT')) return 'GITHUB';
        return 'GOOGLE';
    };

    const renderOAuthAccountsForProvider = (providerLabel) => {
        const listEl = document.getElementById('oauth-picker-accounts-list');
        if (!listEl) return;
        const pKey = normalizeProviderCatalogKey(providerLabel);
        const accounts = oauthAccountsCatalog[pKey] || oauthAccountsCatalog.GOOGLE;
        const stateToken = listEl.dataset.oauthState
            || document.querySelector('#modal-oauth-picker input[name="state"]')?.value
            || '';

        listEl.innerHTML = accounts.map(acc => `
            <form method="post" action="?action=oauth_login">
                <input type="hidden" name="csrf_token" value="${escapeHtml(stateToken)}">
                <input type="hidden" name="state" value="${escapeHtml(stateToken)}">
                <input type="hidden" name="provider" class="oauth-hidden-provider" value="${escapeHtml(providerLabel)}">
                <input type="hidden" name="email" value="${escapeHtml(acc.email)}">
                <input type="hidden" name="full_name" value="${escapeHtml(acc.full_name)}">
                <input type="hidden" name="account_role" value="${escapeHtml(acc.role)}">
                <button type="submit" class="w-full flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-[#0057B8] hover:bg-blue-50/50 dark:hover:bg-slate-800 transition-all text-left">
                    <div class="flex items-center gap-3 min-w-0">
                        <img src="${escapeHtml(acc.avatar)}" alt="" class="w-10 h-10 rounded-full object-cover border border-[#0057B8] shrink-0">
                        <div class="min-w-0">
                            <div class="font-bold text-sm text-slate-900 dark:text-white truncate">${escapeHtml(acc.full_name)}</div>
                            <div class="text-xs text-slate-500 mono truncate">${escapeHtml(acc.email)}</div>
                        </div>
                    </div>
                    <span class="badge ${acc.badgeClass} shrink-0">${escapeHtml(acc.badge)}</span>
                </button>
            </form>
        `).join('');
    };

    document.querySelectorAll('[data-oauth-provider]').forEach(btn => {
        btn.addEventListener('click', () => {
            const providerLabel = btn.dataset.oauthProvider || 'Google';
            const titleEl = document.getElementById('oauth-modal-title');
            const labelEl = document.getElementById('oauth-provider-label');
            if (titleEl) titleEl.textContent = `Đăng nhập bằng ${providerLabel}`;
            if (labelEl) labelEl.textContent = providerLabel;

            renderOAuthAccountsForProvider(providerLabel);
            document.querySelectorAll('.oauth-hidden-provider').forEach(inp => {
                inp.value = providerLabel;
            });
            XanhHubUI.openModal('modal-oauth-picker');
        });
    });

    document.querySelectorAll('[data-toggle-password]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.togglePassword;
            const input = document.getElementById(targetId);
            const icon = btn.querySelector('.material-symbols-outlined');
            if (input) {
                const isPwd = input.type === 'password';
                input.type = isPwd ? 'text' : 'password';
                if (icon) icon.textContent = isPwd ? 'visibility' : 'visibility_off';
            }
        });
    });

    // =========================================================================
    // 2D. HIỆU ỨNG 3D TILT SPOTLIGHT CARDS, SCROLL REVEAL & ANIMATED COUNTERS
    // =========================================================================
    document.querySelectorAll('[data-tilt-card]').forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            card.style.setProperty('--mouse-x', `${x}px`);
            card.style.setProperty('--mouse-y', `${y}px`);
            const rotY = ((x / rect.width) - 0.5) * 5;
            const rotX = (0.5 - (y / rect.height)) * 5;
            card.style.transform = `perspective(900px) rotateX(${rotX.toFixed(2)}deg) rotateY(${rotY.toFixed(2)}deg) translateY(-3px)`;
        });
        card.addEventListener('mouseleave', () => {
            card.style.transform = '';
        });
    });

    if ('IntersectionObserver' in window) {
        const revealObs = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    revealObs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        document.querySelectorAll('.reveal-on-scroll').forEach(el => revealObs.observe(el));

        const countObs = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const targetVal = parseInt(el.dataset.countUp || '100', 10);
                    let cur = 0;
                    const step = Math.max(1, Math.ceil(targetVal / 36));
                    const timer = setInterval(() => {
                        cur += step;
                        if (cur >= targetVal) {
                            cur = targetVal;
                            clearInterval(timer);
                        }
                        el.textContent = cur.toLocaleString('en-US');
                    }, 25);
                    countObs.unobserve(el);
                }
            });
        }, { threshold: 0.4 });

        document.querySelectorAll('[data-count-up]').forEach(el => countObs.observe(el));
    }

    // =========================================================================
    // 3. SÂN KHẤU ĐỒ THỊ LƯỠNG PHÂN GHIM CỐ ĐỊNH (#xanhhub-stage)
    //    Tuân thủ Scrollytelling: Tách biệt cột chữ bên trái và Canvas bên phải
    // =========================================================================
    const canvas = document.getElementById('xanhhub-stage');
    if (canvas) {
        const ctx = canvas.getContext('2d');
        const wrap = canvas.parentElement;
        let stageData = null;
        let currentMode = 'B0'; // 'FORMATION' | 'B0' | 'B1' | 'X'
        let width = 760;
        let height = 460;
        let nodes = [];
        let shiftBoxes = [];
        let hoveredShift = null;
        let tick = 0;

        function resizeCanvas() {
            const rect = wrap ? wrap.getBoundingClientRect() : { width: 760, height: 460 };
            width = Math.max(360, Math.floor(rect.width));
            height = Math.max(320, Math.floor(rect.height));
            canvas.width = width;
            canvas.height = height;
            computeLayout();
        }

        window.addEventListener('resize', resizeCanvas);

        function computeLayout() {
            if (!stageData) return;
            const shifts = stageData.shifts || [];
            const cols = width > 620 ? 3 : 2;
            const rows = Math.ceil(shifts.length / cols);
            const rightZoneX = Math.floor(width * 0.28);
            const availW = width - rightZoneX - 20;
            const gapX = 12;
            const gapY = 12;
            const boxW = Math.max(130, Math.floor((availW - (cols - 1) * gapX) / cols));
            const boxH = Math.max(58, Math.min(72, Math.floor((height - 40 - (rows - 1) * gapY) / rows)));
            const startY = Math.max(18, Math.floor((height - (rows * boxH + (rows - 1) * gapY)) / 2));

            shiftBoxes = shifts.map((s, idx) => {
                const c = idx % cols;
                const r = Math.floor(idx / cols);
                return {
                    ...s,
                    x: rightZoneX + c * (boxW + gapX),
                    y: startY + r * (boxH + gapY),
                    w: boxW,
                    h: boxH
                };
            });

            if (nodes.length === 0 && stageData.volunteers) {
                nodes = stageData.volunteers.map((v, i) => ({
                    ...v,
                    x: 48,
                    y: 28 + i * ((height - 56) / Math.max(1, stageData.volunteers.length - 1)),
                    tx: 48,
                    ty: 28 + i * ((height - 56) / Math.max(1, stageData.volunteers.length - 1)),
                    color: '#0EA5E9',
                    violation: false,
                    assignedShiftId: null
                }));
            }
            updateTargets(currentMode);
        }

        function updateLinhPhucDock(mode) {
            const dockStatus = document.getElementById('linh-phuc-status');
            if (!dockStatus) return;

            if (mode === 'B0') {
                dockStatus.innerHTML = `
                    <span class="badge badge-violation mono">B0: VỠ TRẬN</span>
                    <span style="color:#FDA4AF;">Xếp trùng giờ hàng loạt (35 vi phạm cứng H1/H2/H4) — 0/12 ca hợp lệ.</span>
                `;
            } else if (mode === 'B1') {
                dockStatus.innerHTML = `
                    <span class="swap-pill" style="border-color:#F59E0B; color:#FDE047;">Linh (Y tế + Hậu cần) &rarr; Ca A (Hậu cần)</span>
                    <span class="swap-pill" style="border-color:#E11D48; color:#FDA4AF;">Ca B (Y tế) &rarr; BỎ TRỐNG (Phúc thiếu Y tế)</span>
                `;
            } else {
                dockStatus.innerHTML = `
                    <span class="swap-pill" style="border-color:#10B981; color:#6EE7B7;">Hoán đổi Kuhn: Phúc &rarr; Ca A (Hậu cần)</span>
                    <span class="swap-pill" style="border-color:#10B981; color:#6EE7B7;">Linh &rarr; Ca B (Y tế) · Đạt 12/12 ca (100%)</span>
                `;
            }
        }

        function updateTargets(mode) {
            currentMode = mode;
            if (!stageData || !nodes.length) return;

            document.querySelectorAll('[data-stage-mode]').forEach(btn => {
                if (btn.dataset.stageMode === mode) {
                    btn.classList.add('btn-primary');
                    btn.classList.remove('btn-ghost-dark');
                } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-ghost-dark');
                }
            });

            updateLinhPhucDock(mode);

            const hudLabel = document.getElementById('stage-mode-label');
            const hudStats = document.getElementById('stage-mode-stats');

            if (mode === 'FORMATION') {
                if (hudLabel) hudLabel.textContent = 'Đội hình 30 Tình nguyện viên × 12 Ca trực Chiến dịch D3';
                if (hudStats) hudStats.textContent = '30 TNV · 12 Ca trực chồng lấn · 5 Kỹ năng xác thực';
                nodes.forEach((n, i) => {
                    const angle = (i / nodes.length) * Math.PI * 2;
                    const radius = Math.min(width, height) * 0.30;
                    n.tx = width * 0.52 + Math.cos(angle) * radius;
                    n.ty = height * 0.50 + Math.sin(angle) * radius;
                    n.color = '#0EA5E9';
                    n.violation = false;
                    n.assignedShiftId = null;
                });
                return;
            }

            const plan = (stageData.plans && stageData.plans[mode]) ? stageData.plans[mode] : stageData[mode];
            if (!plan) return;

            const m = plan.metrics;
            if (hudLabel) {
                const titles = {
                    B0: 'Phương pháp B0 (FIFO Không kiểm tra): Xung đột giờ & Sai kỹ năng',
                    B1: 'Phương pháp B1 (FIFO Có kiểm tra): Kẹt bẫy tham lam — Bỏ trống 6/12 ca',
                    X: 'Thuật toán Xanh Hub (2 Pha × 3 Bước): Lấp đầy 12/12 ca (100%) · 0 lỗi'
                };
                hudLabel.textContent = titles[mode] || mode;
            }
            if (hudStats && m) {
                hudStats.textContent = `Ca hợp lệ: ${m.valid_covered_shifts}/${m.total_shifts} (${m.valid_coverage_rate}%) · Vi phạm cứng: ${m.total_violations} · Độ lệch tải σ = ${m.load_std_dev}`;
            }

            const firstAssignByVol = {};
            const volHasViolation = {};
            (plan.decisions || plan.assignments || []).forEach(a => {
                if (!firstAssignByVol[a.volunteer_id]) {
                    firstAssignByVol[a.volunteer_id] = a;
                }
                if (a.violations && a.violations.length > 0) {
                    volHasViolation[a.volunteer_id] = true;
                }
            });

            nodes.forEach((n, idx) => {
                const railY = 24 + idx * ((height - 48) / Math.max(1, nodes.length - 1));
                n.railX = 36;
                n.railY = railY;

                const a = firstAssignByVol[n.id];
                if (a) {
                    const box = shiftBoxes.find(b => b.id === a.shift_id);
                    if (box) {
                        const offset = (idx % 4) * 20 - 30;
                        n.tx = box.x + box.w / 2 + offset;
                        n.ty = box.y + box.h - 14;
                        n.violation = !!volHasViolation[n.id];
                        n.color = n.violation ? '#E11D48' : '#10B981';
                        n.assignedShiftId = box.id;
                        return;
                    }
                }
                n.tx = 36;
                n.ty = railY;
                n.violation = false;
                n.color = '#64748B';
                n.assignedShiftId = null;
            });
        }

        canvas.addEventListener('mousemove', (e) => {
            const rect = canvas.getBoundingClientRect();
            const mx = e.clientX - rect.left;
            const my = e.clientY - rect.top;
            hoveredShift = shiftBoxes.find(b => mx >= b.x && mx <= b.x + b.w && my >= b.y && my <= b.y + b.h) || null;
        });

        function renderLoop() {
            tick++;
            ctx.clearRect(0, 0, width, height);

            // Lưới tọa độ kỹ thuật tinh tế
            ctx.strokeStyle = 'rgba(56, 189, 248, 0.045)';
            ctx.lineWidth = 1;
            for (let x = 0; x < width; x += 48) {
                ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, height); ctx.stroke();
            }
            for (let y = 0; y < height; y += 48) {
                ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(width, y); ctx.stroke();
            }

            if (currentMode !== 'FORMATION' && stageData) {
                const plan = (stageData.plans && stageData.plans[currentMode]) ? stageData.plans[currentMode] : stageData[currentMode];
                const unassignedIds = new Set((plan?.unassigned || []).filter(u => u.item_type === 'SLOT').map(u => u.shift_id));

                // Nhãn cột nguồn TNV bên trái
                ctx.fillStyle = '#94A3B8';
                ctx.font = '700 10px "JetBrains Mono", monospace';
                ctx.fillText('30 TNV', 16, 14);

                // Vẽ đường cung Bezier nối từ cột TNV sang từng Ca trực
                nodes.forEach(n => {
                    if (n.assignedShiftId) {
                        ctx.save();
                        ctx.beginPath();
                        const sx = n.railX || 36;
                        const sy = n.railY || height * 0.5;
                        ctx.moveTo(sx, sy);
                        ctx.bezierCurveTo(width * 0.16, sy, width * 0.20, n.y, n.x, n.y);
                        ctx.strokeStyle = n.violation ? 'rgba(225, 29, 72, 0.45)' : 'rgba(16, 185, 129, 0.28)';
                        ctx.lineWidth = n.violation ? 1.7 : 1.15;
                        if (n.violation) ctx.setLineDash([4, 4]);
                        ctx.stroke();
                        ctx.restore();
                    }
                });

                shiftBoxes.forEach(b => {
                    const isUnassigned = unassignedIds.has(b.id);
                    const isHover = hoveredShift && hoveredShift.id === b.id;
                    ctx.save();
                    ctx.fillStyle = isUnassigned ? 'rgba(225, 29, 72, 0.18)' : 'rgba(5, 150, 105, 0.16)';
                    ctx.strokeStyle = isHover ? '#FACC15' : (isUnassigned ? '#F43F5E' : '#10B981');
                    ctx.lineWidth = isHover ? 2.2 : 1.4;
                    ctx.beginPath();
                    ctx.roundRect(b.x, b.y, b.w, b.h, 8);
                    ctx.fill();
                    ctx.stroke();

                    ctx.fillStyle = '#F8FAFC';
                    ctx.font = '700 11.5px "Be Vietnam Pro", sans-serif';
                    ctx.fillText(`${b.code} (cần ${b.min_people})`, b.x + 10, b.y + 19);

                    ctx.fillStyle = isUnassigned ? '#FDA4AF' : '#6EE7B7';
                    ctx.font = '600 10.5px "JetBrains Mono", monospace';
                    ctx.fillText(isUnassigned ? '✗ THIẾU NGƯỜI' : '✓ ĐỦ ĐỊNH BIÊN', b.x + 10, b.y + 36);
                    ctx.restore();
                });
            }

            // Vẽ các nút tình nguyện viên kèm sóng xung kích khi có vi phạm B0
            nodes.forEach(n => {
                n.x += (n.tx - n.x) * 0.09;
                n.y += (n.ty - n.y) * 0.09;

                if (n.violation) {
                    const pulseR = 6 + ((tick % 42) / 42) * 14;
                    const pulseAlpha = Math.max(0, 0.65 - ((tick % 42) / 42) * 0.65);
                    ctx.save();
                    ctx.beginPath();
                    ctx.arc(n.x, n.y, pulseR, 0, Math.PI * 2);
                    ctx.strokeStyle = `rgba(244, 63, 94, ${pulseAlpha})`;
                    ctx.lineWidth = 1.8;
                    ctx.stroke();
                    ctx.restore();
                }

                ctx.save();
                ctx.beginPath();
                ctx.arc(n.x, n.y, n.violation ? 6 : 5, 0, Math.PI * 2);
                ctx.fillStyle = n.color;
                ctx.shadowColor = n.color;
                ctx.shadowBlur = 8;
                ctx.fill();
                ctx.strokeStyle = '#FFFFFF';
                ctx.lineWidth = 1.2;
                ctx.stroke();
                ctx.restore();
            });

            requestAnimationFrame(renderLoop);
        }

        fetch('assets/data/d3_stage_comparison.json')
            .then(r => r.json())
            .then(data => {
                stageData = data;
                resizeCanvas();
                renderLoop();
            })
            .catch(() => {});

        document.querySelectorAll('[data-stage-mode]').forEach(btn => {
            btn.addEventListener('click', () => updateTargets(btn.dataset.stageMode));
        });

        const sections = document.querySelectorAll('[data-scene-trigger]');
        if (sections.length > 0 && 'IntersectionObserver' in window) {
            const obs = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const mode = entry.target.getAttribute('data-scene-trigger');
                        const sceneId = entry.target.getAttribute('id');
                        if (mode) updateTargets(mode);

                        document.querySelectorAll('.scrolly-step-card').forEach(card => {
                            card.classList.toggle('is-active', card.id === sceneId);
                        });

                        if (sceneId) {
                            document.querySelectorAll('.scene-dot').forEach(dot => {
                                dot.classList.toggle('active', dot.getAttribute('href') === '#' + sceneId);
                            });
                        }
                    }
                });
            }, { threshold: 0.45 });
            sections.forEach(s => obs.observe(s));
        }
    }

    // Đồng hồ đếm ngược 30 giây cho Cảnh 05 trên Trang chủ
    const homeQrCountdown = document.getElementById('home-qr-countdown');
    if (homeQrCountdown) {
        setInterval(() => {
            const rem = 30 - (Math.floor(Date.now() / 1000) % 30);
            homeQrCountdown.textContent = `${rem}s`;
        }, 1000);
    }

    // =========================================================================
    // 4. BẢNG CA KÉO THẢ KANBAN (CN4) CÓ DỰ BÁO HỢP LỆ TRƯỚC KHI THẢ
    // =========================================================================
    const volCards = document.querySelectorAll('.vol-card[draggable="true"]');
    const shiftBoxesDom = document.querySelectorAll('.shift-box[data-shift-id]');
    let draggedVolunteerId = null;
    let pendingOverridePayload = null;

    function highlightCompatibleShifts(card) {
        const volSkills = (card.dataset.skills || '').split(',').filter(Boolean);
        const assignedShifts = (card.dataset.assignedShifts || '').split(',').filter(Boolean);

        shiftBoxesDom.forEach(box => {
            const isLocked = box.dataset.isLocked === '1';
            const isFull = parseInt(box.dataset.assignedCount || '0', 10) >= parseInt(box.dataset.maxPeople || '99', 10);
            const reqSkill = box.dataset.reqSkill || '';
            const shiftId = box.dataset.shiftId;

            let compatible = true;
            if (isLocked || isFull || assignedShifts.includes(shiftId)) {
                compatible = false;
            } else if (reqSkill && !volSkills.includes(reqSkill)) {
                compatible = false;
            }

            box.classList.toggle('candidate-compatible', compatible);
            box.classList.toggle('candidate-blocked', !compatible);
        });
    }

    function clearShiftHighlights() {
        shiftBoxesDom.forEach(box => {
            box.classList.remove('candidate-compatible', 'candidate-blocked', 'drop-hover');
        });
    }

    volCards.forEach(card => {
        card.addEventListener('dragstart', (e) => {
            draggedVolunteerId = card.dataset.volunteerId;
            card.classList.add('dragging');
            e.dataTransfer.setData('text/plain', draggedVolunteerId);
            highlightCompatibleShifts(card);
        });

        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            clearShiftHighlights();
        });
    });

    shiftBoxesDom.forEach(box => {
        box.addEventListener('dragover', (e) => {
            e.preventDefault();
            box.classList.add('drop-hover');
        });

        box.addEventListener('dragleave', () => {
            box.classList.remove('drop-hover');
        });

        box.addEventListener('drop', (e) => {
            e.preventDefault();
            box.classList.remove('drop-hover');
            clearShiftHighlights();
            const volId = e.dataTransfer.getData('text/plain') || draggedVolunteerId;
            const shiftId = box.dataset.shiftId;
            const reqId = box.dataset.reqId || '';
            const actId = box.dataset.activityId || '3';
            if (!volId || !shiftId) return;

            submitManualAssignment(actId, shiftId, volId, reqId, '', box);
        });
    });

    // Hỗ trợ gán bằng bàn phím chuẩn WCAG 2.1 AA
    document.querySelectorAll('.btn-kbd-assign').forEach(btn => {
        btn.addEventListener('click', () => {
            const volId = btn.dataset.volunteerId;
            const actId = btn.dataset.activityId || '3';
            const sel = document.querySelector(`.kbd-shift-select[data-volunteer-id="${volId}"]`);
            if (!sel || !sel.value) {
                XanhHubUI.toast('warning', 'Chưa chọn ca trực', 'Vui lòng chọn một ca trực trong danh sách thả xuống trước khi bấm Gán.');
                return;
            }
            const opt = sel.options[sel.selectedIndex];
            const shiftId = sel.value;
            const reqId = opt.dataset.reqId || '';
            const targetBox = document.querySelector(`.shift-box[data-shift-id="${shiftId}"]`);
            submitManualAssignment(actId, shiftId, volId, reqId, '', targetBox);
        });
    });

    function submitManualAssignment(actId, shiftId, volId, reqId, overrideReason, targetBox, unlockConfirmed = false) {
        const body = new URLSearchParams({
            activity_id: actId,
            shift_id: shiftId,
            volunteer_id: volId,
            requirement_id: reqId,
            override_reason: overrideReason,
            unlock_confirmed: unlockConfirmed ? '1' : ''
        });

        fetch('?action=api_manual_assign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(res => {
                if (!res.allowed && !res.requires_override) {
                    if (targetBox) {
                        targetBox.classList.remove('shake-error');
                        void targetBox.offsetWidth;
                        targetBox.classList.add('shake-error');
                    }
                    XanhHubUI.toast(
                        'error',
                        'Không thể phân công (Chưa đáp ứng điều kiện ca trực)',
                        (res.messages_vi || ['Không thể gán ca']).join('<br>'),
                        res.hard_violations || []
                    );
                    return;
                }

                if (res.requires_override && !overrideReason) {
                    pendingOverridePayload = { actId, shiftId, volId, reqId, targetBox };
                    const warnBox = document.getElementById('override-warning-messages');
                    const codeBox = document.getElementById('override-warning-codes');
                    if (warnBox) warnBox.innerHTML = (res.messages_vi || []).map(m => `<li>${m}</li>`).join('');
                    if (codeBox) codeBox.innerHTML = (res.soft_warnings || []).map(c => `<span class="badge badge-warning mono">${c}</span>`).join(' ');
                    XanhHubUI.openModal('modal-override');
                    return;
                }

                XanhHubUI.toast('success', 'Đã ghi nhận phân công', res.messages_vi ? res.messages_vi[0] : 'Phân công thành công!');
                setTimeout(() => window.location.reload(), 650);
            })
            .catch(() => {
                XanhHubUI.toast('error', 'Lỗi kết nối', 'Không thể kết nối tới máy chủ kiểm tra ràng buộc.');
            });
    }

    const overrideForm = document.getElementById('form-confirm-override');
    if (overrideForm) {
        overrideForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const reasonInput = document.getElementById('input-override-reason');
            const reason = reasonInput ? reasonInput.value.trim() : '';
            if (!reason) {
                XanhHubUI.toast('warning', 'Thiếu lý do kiểm toán', 'Vui lòng nhập lý do ghi đè cảnh báo mềm để lưu vào Nhật ký kiểm toán.');
                return;
            }
            if (pendingOverridePayload) {
                XanhHubUI.closeModal('modal-override');
                submitManualAssignment(
                    pendingOverridePayload.actId,
                    pendingOverridePayload.shiftId,
                    pendingOverridePayload.volId,
                    pendingOverridePayload.reqId,
                    reason,
                    pendingOverridePayload.targetBox,
                    true
                );
            }
        });
    }

    // =========================================================================
    // 5. GỠ PHÂN CÔNG, HOÀN TÁC (UNDO) & KHÓA / MỞ KHÓA CA TRỰC
    // =========================================================================
    document.querySelectorAll('.btn-remove-assign').forEach(btn => {
        btn.addEventListener('click', () => {
            const assignId = btn.dataset.assignmentId;
            fetch('?action=api_remove_assignment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ assignment_id: assignId }).toString()
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        XanhHubUI.toast('error', 'Không thể gỡ phân công', res.message_vi, res.reason_code ? [res.reason_code] : []);
                        return;
                    }
                    XanhHubUI.toast('success', 'Đã gỡ phân công', res.message_vi);
                    setTimeout(() => window.location.reload(), 550);
                });
        });
    });

    const btnUndo = document.getElementById('btn-undo-assign');
    if (btnUndo) {
        btnUndo.addEventListener('click', () => {
            const actId = btnUndo.dataset.activityId || '3';
            fetch('?action=api_undo_last_assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ activity_id: actId }).toString()
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        XanhHubUI.toast('warning', 'Hoàn tác (Undo)', res.message_vi);
                        return;
                    }
                    XanhHubUI.toast('success', 'Đã hoàn tác phân công', res.message_vi);
                    setTimeout(() => window.location.reload(), 550);
                });
        });
    }

    let pendingLockShiftId = null;
    document.querySelectorAll('.btn-toggle-lock').forEach(btn => {
        btn.addEventListener('click', () => {
            pendingLockShiftId = btn.dataset.shiftId;
            const shiftCode = btn.dataset.shiftCode || '';
            const isLocked = btn.dataset.isLocked === '1';
            const titleEl = document.getElementById('lock-modal-title');
            const descEl = document.getElementById('lock-modal-desc');
            if (titleEl) {
                titleEl.textContent = isLocked ? `Mở khóa ca trực [${shiftCode}]` : `Khóa chốt ca trực [${shiftCode}]`;
            }
            if (descEl) {
                descEl.textContent = isLocked
                    ? 'Ca trực này đã chốt trước đó. Vui lòng nhập lý do để mở khóa và cho phép điều chỉnh nhân sự:'
                    : 'Sau khi khóa chốt ca, danh sách nhân sự trong ca này sẽ được bảo vệ cho tới khi mở khóa lại:';
            }
            XanhHubUI.openModal('modal-lock-shift');
        });
    });

    const formLockShift = document.getElementById('form-lock-shift');
    if (formLockShift) {
        formLockShift.addEventListener('submit', (e) => {
            e.preventDefault();
            const reason = (document.getElementById('input-lock-reason')?.value || '').trim();
            if (!reason || !pendingLockShiftId) return;
            XanhHubUI.closeModal('modal-lock-shift');

            fetch('?action=api_toggle_shift_lock', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ shift_id: pendingLockShiftId, reason }).toString()
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        XanhHubUI.toast('error', 'Lỗi khóa/mở khóa ca', res.message_vi);
                        return;
                    }
                    XanhHubUI.toast('success', 'Cập nhật trạng thái ca', res.message_vi);
                    setTimeout(() => window.location.reload(), 600);
                });
        });
    }

    // =========================================================================
    // 6. BỘ LỌC TÌM KIẾM TÌNH NGUYỆN VIÊN & CA TRỰC TRỰC TIẾP TRÊN BẢNG
    // =========================================================================
    const volSearch = document.getElementById('vol-pool-search');
    const volSkillFilter = document.getElementById('vol-pool-skill-filter');

    function filterVolunteerPool() {
        const q = (volSearch?.value || '').toLowerCase().trim();
        const sk = volSkillFilter?.value || '';
        document.querySelectorAll('.vol-card').forEach(card => {
            const text = (card.textContent || '').toLowerCase();
            const skills = (card.dataset.skills || '').split(',');
            const matchText = !q || text.includes(q);
            const matchSkill = !sk || skills.includes(sk);
            card.style.display = (matchText && matchSkill) ? '' : 'none';
        });
    }

    if (volSearch) volSearch.addEventListener('input', filterVolunteerPool);
    if (volSkillFilter) volSkillFilter.addEventListener('change', filterVolunteerPool);

    document.querySelectorAll('[data-shift-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-shift-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const filter = btn.dataset.shiftFilter;
            shiftBoxesDom.forEach(box => {
                if (filter === 'ALL') {
                    box.style.display = '';
                } else if (filter === 'UNDERSTAFFED') {
                    box.style.display = box.classList.contains('shift-understaffed') ? '' : 'none';
                } else if (filter === 'LOCKED') {
                    box.style.display = box.dataset.isLocked === '1' ? '' : 'none';
                }
            });
        });
    });

    // =========================================================================
    // 7. BÁO VẮNG ĐỘT XUẤT & TRỢ LÝ AI A3 ĐỀ XUẤT TOP-5 NGƯỜI THAY THẾ
    // =========================================================================
    document.querySelectorAll('.btn-open-absence').forEach(btn => {
        btn.addEventListener('click', () => {
            const assignId = btn.dataset.assignmentId;
            const listEl = document.getElementById('a3-candidates-container');
            const targetEl = document.getElementById('a3-target-summary');
            const inputAssign = document.getElementById('absence-assignment-id');
            if (inputAssign) inputAssign.value = assignId;
            if (listEl) listEl.innerHTML = '<div style="padding:1rem; color:var(--text-muted);">Đang tìm ứng viên thay thế phù hợp lịch rảnh và kỹ năng...</div>';

            XanhHubUI.openModal('modal-absence');

            fetch(`?action=api_suggest_replacements&assignment_id=${encodeURIComponent(assignId)}`)
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        if (listEl) listEl.innerHTML = `<div class="badge badge-violation">${res.message_vi}</div>`;
                        return;
                    }
                    if (targetEl && res.target) {
                        targetEl.innerHTML = `Ca trực: <strong>[${res.target.shift_code}] ${res.target.shift_title}</strong> · Người báo vắng: <strong>${res.target.vol_name} (${res.target.student_code})</strong>`;
                    }
                    const cands = res.candidates || [];
                    if (cands.length === 0) {
                        listEl.innerHTML = `<div style="padding:0.75rem; background:var(--vang-canh-bao-nen); color:var(--vang-canh-bao-chu); border-radius:0.5rem;">Không tìm thấy ứng viên rảnh không trùng giờ có sẵn kỹ năng này. Hệ thống sẽ chỉ ghi nhận báo vắng và để trống vị trí.</div>`;
                        return;
                    }
                    listEl.innerHTML = cands.map((c, idx) => `
                        <label style="display:flex; align-items:flex-start; justify-content:space-between; gap:0.75rem; padding:0.7rem 0.85rem; border:1px solid var(--border-color); border-radius:0.6rem; margin-bottom:0.5rem; cursor:pointer; background:var(--bg-surface-alt);">
                            <div style="display:flex; gap:0.65rem; align-items:flex-start;">
                                <input type="radio" name="replacement_volunteer_id" value="${c.volunteer_id}" ${idx === 0 ? 'checked' : ''} style="margin-top:0.25rem;">
                                <div>
                                    <div style="font-weight:700;">#${idx + 1} · ${c.full_name} <span class="mono" style="font-size:0.78rem; color:var(--text-muted);">(${c.student_code})</span></div>
                                    <div style="font-size:0.78rem; color:var(--text-secondary); margin-top:0.15rem;">${c.explanation_vi}</div>
                                </div>
                            </div>
                            <span class="badge badge-valid mono">Điểm: ${c.score}</span>
                        </label>
                    `).join('') + `
                        <label style="display:flex; align-items:center; gap:0.5rem; padding:0.45rem 0.6rem; font-size:0.82rem; color:var(--text-muted); cursor:pointer;">
                            <input type="radio" name="replacement_volunteer_id" value="">
                            <span>Chỉ hủy phân công người vắng, chưa gán người thay ngay</span>
                        </label>
                    `;
                });
        });
    });

    const formAbsence = document.getElementById('form-report-absence');
    if (formAbsence) {
        formAbsence.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(formAbsence);
            const params = new URLSearchParams();
            formData.forEach((v, k) => params.append(k, String(v)));

            fetch('?action=api_report_absence', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
                .then(r => r.json())
                .then(res => {
                    XanhHubUI.closeModal('modal-absence');
                    if (!res.ok) {
                        XanhHubUI.toast('error', 'Không thể thay thế', res.message_vi);
                        return;
                    }
                    XanhHubUI.toast('success', 'Đã xử lý báo vắng & thay người', res.message_vi);
                    setTimeout(() => window.location.reload(), 700);
                });
        });
    }

    // =========================================================================
    // 8. MÁY CHIẾU QR ĐIỂM DANH 30 GIÂY XOAY VÒNG & MÁY QUÉT QR ĐA NĂNG
    // =========================================================================
    let qrTimerInterval = null;
    let activeQrShiftId = 201;

    function refreshLiveQr(shiftId) {
        activeQrShiftId = shiftId || activeQrShiftId;
        fetch(`?action=api_qr_refresh&shift_id=${encodeURIComponent(activeQrShiftId)}`)
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const svgBox = document.getElementById('live-qr-svg-box');
                const tokenBox = document.getElementById('live-qr-token-text');
                const countBox = document.getElementById('live-qr-countdown');
                const metaBox = document.getElementById('live-qr-meta');
                const directUrlBox = document.getElementById('live-qr-direct-url');

                if (svgBox) {
                    if (svgBox.dataset.token !== res.token) {
                        svgBox.innerHTML = res.svg;
                        svgBox.dataset.token = res.token || '';
                    }
                    svgBox.dataset.directUrl = res.direct_url || '';
                }
                if (tokenBox) {
                    tokenBox.textContent = res.token || '';
                    tokenBox.dataset.token = res.token || '';
                    tokenBox.dataset.directUrl = res.direct_url || '';
                }
                if (directUrlBox) directUrlBox.textContent = res.direct_url || '';
                if (metaBox) {
                    metaBox.textContent = `[${res.shift_code || ''}] ${res.location_name || ''} · Quân số: ${res.assigned_count ?? 0}/${res.max_people ?? 5} TNV`;
                }
                if (countBox) countBox.textContent = `${res.seconds_remaining}s`;
            })
            .catch(() => {});
    }

    document.querySelectorAll('.btn-open-qr-projector').forEach(btn => {
        btn.addEventListener('click', () => {
            const shiftId = btn.dataset.shiftId || '201';
            const shiftTitle = btn.dataset.shiftTitle || 'Ca trực chiến dịch';
            const titleEl = document.getElementById('qr-projector-shift-title');
            if (titleEl) titleEl.textContent = shiftTitle;

            XanhHubUI.openModal('modal-qr-projector');
            refreshLiveQr(shiftId);
            if (qrTimerInterval) clearInterval(qrTimerInterval);
            qrTimerInterval = setInterval(() => refreshLiveQr(activeQrShiftId), 1000);
        });
    });

    const btnDownloadQrPng = document.getElementById('btn-download-qr-png');
    if (btnDownloadQrPng) {
        btnDownloadQrPng.addEventListener('click', () => {
            const svgEl = document.querySelector('#live-qr-svg-box svg');
            if (!svgEl) {
                XanhHubUI.toast('warning', 'Chưa sẵn sàng', 'Mã QR đang được tải từ máy chủ, vui lòng thử lại sau 1 giây.');
                return;
            }
            const svgData = new XMLSerializer().serializeToString(svgEl);
            const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
            const url = URL.createObjectURL(svgBlob);
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width = 640;
                canvas.height = 720;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                const shiftTitle = document.getElementById('qr-projector-shift-title')?.textContent || 'Xanh Hub Check-in';
                const tokenStr = document.getElementById('live-qr-token-text')?.textContent || '';

                ctx.fillStyle = '#0F172A';
                ctx.font = 'bold 22px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('XANH HUB — MÃ QR ĐIỂM DANH CA TRỰC', 320, 42);

                ctx.fillStyle = '#0057B8';
                ctx.font = 'bold 17px sans-serif';
                ctx.fillText(shiftTitle.slice(0, 48), 320, 72);

                ctx.drawImage(img, 60, 96, 520, 520);

                ctx.fillStyle = '#475569';
                ctx.font = '14px monospace';
                ctx.fillText(tokenStr, 320, 656);

                ctx.fillStyle = '#64748B';
                ctx.font = '13px sans-serif';
                ctx.fillText('Quét bằng Camera, Zalo hoặc Máy quét Xanh Hub (Tải ảnh QR)', 320, 686);

                URL.revokeObjectURL(url);
                const pngUrl = canvas.toDataURL('image/png');
                const a = document.createElement('a');
                a.href = pngUrl;
                a.download = `QR-DiemDanh-Ca-${activeQrShiftId}.png`;
                document.body.appendChild(a);
                a.click();
                a.remove();
                XanhHubUI.toast('success', 'Đã tải ảnh QR (.png)', 'Bạn có thể dùng ảnh PNG này để quét thử ở tab "Tải ảnh QR" trong Máy quét.');
            };
            img.src = url;
        });
    }

    const btnCopyQrToken = document.getElementById('btn-copy-qr-token');
    if (btnCopyQrToken) {
        btnCopyQrToken.addEventListener('click', () => {
            const directUrl = document.getElementById('live-qr-direct-url')?.textContent?.trim()
                || document.getElementById('live-qr-token-text')?.textContent?.trim()
                || '';
            if (!directUrl) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(directUrl).then(() => {
                    XanhHubUI.toast('success', 'Đã sao chép liên kết QR', 'Bạn có thể dán vào trình duyệt hoặc Máy quét QR để kiểm thử.');
                }).catch(() => {});
            }
        });
    }

    const btnTestScanProjector = document.getElementById('btn-test-scan-projector');
    if (btnTestScanProjector) {
        btnTestScanProjector.addEventListener('click', () => {
            const directUrl = document.getElementById('live-qr-direct-url')?.textContent?.trim()
                || document.getElementById('live-qr-token-text')?.textContent?.trim()
                || '';
            const shiftTitle = document.getElementById('qr-projector-shift-title')?.textContent || '';
            XanhHubUI.closeModal('modal-qr-projector');
            openQrScannerModal(0, shiftTitle);
            const manualTab = document.querySelector('.qr-mode-tab[data-mode="manual"]');
            if (manualTab) manualTab.click();
            const manualInp = document.getElementById('qr-manual-input');
            if (manualInp && directUrl) manualInp.value = directUrl;
            if (directUrl) submitQrCheckin(directUrl, 0);
        });
    }

    // =========================================================================
    // MÁY QUÉT MÃ QR HIỆN TRƯỜNG (CAMERA SCANNER / JSQR / TẢI ẢNH / DÁN MÃ)
    // =========================================================================
    let qrScanStream = null;
    let qrScanAnimFrame = null;
    let activeScanAssignmentId = 0;
    let isQrScanning = false;

    modalCloseHooks.push((modalId) => {
        if (modalId === 'modal-qr-projector' && qrTimerInterval) {
            clearInterval(qrTimerInterval);
            qrTimerInterval = null;
        }
        if (modalId === 'modal-qr-scanner') {
            stopQrCamera();
        }
    });

    function playScanSuccessChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        } catch (e) {}
    }

    function showQrResultBox(ok, html) {
        const box = document.getElementById('qr-scan-result-box');
        if (!box) return;
        box.className = ok
            ? 'mt-4 p-3.5 rounded-xl border text-xs leading-relaxed bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300 dark:border-emerald-700 text-emerald-900 dark:text-emerald-200'
            : 'mt-4 p-3.5 rounded-xl border text-xs leading-relaxed bg-rose-50 dark:bg-rose-950/40 border-rose-300 dark:border-rose-700 text-rose-900 dark:text-rose-200';
        box.innerHTML = html;
        box.classList.remove('hidden');
    }

    function submitQrCheckin(token, assignId) {
        if (!token) return;
        const bodyParams = new URLSearchParams();
        if (assignId) bodyParams.append('assignment_id', assignId);
        bodyParams.append('token', token);
        bodyParams.append('scan_mode', 'VALID');

        fetch('?action=api_qr_scan', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: bodyParams.toString()
        })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) {
                    XanhHubUI.toast('error', 'Từ chối điểm danh QR', res.message_vi, res.error_code ? [res.error_code] : []);
                    const fb = document.getElementById('qr-camera-feedback');
                    if (fb) fb.innerHTML = `<span class="text-rose-500 font-bold">${escapeHtml(res.message_vi)}</span>`;
                    showQrResultBox(false, `
                        <div class="font-extrabold mb-1">Không thể xác thực mã QR (${escapeHtml(res.error_code || 'INVALID')})</div>
                        <div>${escapeHtml(res.message_vi || '')}</div>
                    `);
                    return;
                }

                playScanSuccessChime();
                stopQrCamera();

                if (res.scan_type === 'VERIFY_CODE') {
                    XanhHubUI.toast('success', 'Nhận diện Mã Chứng nhận Số', res.message_vi, [res.verify_code || 'VERIFY']);
                    showQrResultBox(true, `
                        <div class="font-extrabold text-sm mb-1">✔ Nhận diện Mã Tra cứu Chứng nhận / Văn bản Số</div>
                        <div class="mb-2">${escapeHtml(res.message_vi || '')}</div>
                        <a href="${escapeHtml(res.redirect_url || '?view=verify')}" class="btn btn-sm btn-primary inline-flex items-center gap-1">
                            <span>Mở trang Xác thực Mã ${escapeHtml(res.verify_code || '')}</span> &rarr;
                        </a>
                    `);
                    return;
                }

                if (res.scan_type === 'COORDINATOR_PREVIEW') {
                    XanhHubUI.toast('success', 'Mã QR Ca trực Hợp lệ', res.message_vi, [res.shift_code || 'QR_VALID']);
                    showQrResultBox(true, `
                        <div class="font-extrabold text-sm mb-1">✔ Kiểm thử Mã QR Thành công (Chế độ Ban Chỉ Huy)</div>
                        <div><strong>Ca trực:</strong> [${escapeHtml(res.shift_code || '')}] ${escapeHtml(res.shift_title || '')}</div>
                        <div><strong>Địa điểm:</strong> ${escapeHtml(res.location_name || '')}</div>
                        <div class="mt-1 text-[11px] opacity-85">${escapeHtml(res.message_vi || '')}</div>
                    `);
                    return;
                }

                XanhHubUI.toast('success', 'Điểm danh QR thành công', res.message_vi, [res.shift_code || 'CHECKED_IN']);
                showQrResultBox(true, `
                    <div class="font-extrabold text-sm mb-1">✔ Điểm danh Thành công (CHECKED_IN)</div>
                    <div><strong>Tình nguyện viên:</strong> ${escapeHtml(res.volunteer_name || '')} (${escapeHtml(res.student_code || '')})</div>
                    <div><strong>Ca trực:</strong> [${escapeHtml(res.shift_code || '')}] ${escapeHtml(res.shift_title || '')}</div>
                    <div><strong>Thời gian:</strong> ${escapeHtml(res.checked_in_at || '')}</div>
                `);
                setTimeout(() => {
                    window.XanhHubUI.closeModal('modal-qr-scanner');
                    window.location.reload();
                }, 1300);
            })
            .catch(() => {
                XanhHubUI.toast('error', 'Lỗi kết nối máy chủ', 'Không thể gửi dữ liệu điểm danh. Vui lòng thử lại.');
            });
    }

    function openQrScannerModal(assignId, shiftTitle) {
        activeScanAssignmentId = assignId || 0;
        const shiftLbl = document.getElementById('qr-scanner-shift-label');
        if (shiftLbl) {
            shiftLbl.textContent = shiftTitle || (assignId ? `Ca trực #${assignId}` : 'Tự động nhận diện mã ca trực hoặc giấy chứng nhận');
        }
        const resBox = document.getElementById('qr-scan-result-box');
        if (resBox) resBox.classList.add('hidden');
        if (window.XanhHubUI && typeof window.XanhHubUI.openModal === 'function') {
            window.XanhHubUI.openModal('modal-qr-scanner');
        }
    }

    document.querySelectorAll('.btn-sim-qr-scan, .btn-open-qr-scanner').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const assignId = btn.dataset.assignmentId || 0;
            const shiftTitle = btn.dataset.shiftTitle || btn.dataset.shiftCode || '';
            openQrScannerModal(assignId, shiftTitle);
        });
    });

    // Chuyển đổi các tab phương thức quét
    document.querySelectorAll('.qr-mode-tab').forEach(tabBtn => {
        tabBtn.addEventListener('click', () => {
            const mode = tabBtn.dataset.mode;
            document.querySelectorAll('.qr-mode-tab').forEach(b => {
                b.classList.remove('bg-emerald-100', 'dark:bg-emerald-950', 'text-emerald-800', 'dark:text-emerald-300');
                b.classList.add('text-slate-500', 'dark:text-slate-400');
            });
            tabBtn.classList.add('bg-emerald-100', 'dark:bg-emerald-950', 'text-emerald-800', 'dark:text-emerald-300');
            tabBtn.classList.remove('text-slate-500', 'dark:text-slate-400');

            const pCam = document.getElementById('qr-panel-camera');
            const pFile = document.getElementById('qr-panel-file');
            const pManual = document.getElementById('qr-panel-manual');
            if (pCam) pCam.classList.toggle('hidden', mode !== 'camera');
            if (pFile) pFile.classList.toggle('hidden', mode !== 'file');
            if (pManual) pManual.classList.toggle('hidden', mode !== 'manual');

            if (mode !== 'camera') {
                stopQrCamera();
            }
        });
    });

    // Khởi động Camera quét thời gian thực
    async function startQrCamera() {
        const video = document.getElementById('qr-scanner-video');
        const canvas = document.getElementById('qr-scanner-canvas');
        const reticle = document.getElementById('qr-viewfinder-reticle');
        const placeholder = document.getElementById('qr-camera-placeholder');
        const btnStop = document.getElementById('btn-stop-camera');
        const feedback = document.getElementById('qr-camera-feedback');
        if (!video || !canvas) return;

        try {
            if (placeholder) placeholder.classList.add('hidden');
            if (reticle) reticle.classList.remove('hidden');
            if (btnStop) btnStop.classList.remove('hidden');
            if (feedback) feedback.innerHTML = '<span>Đưa mã QR vào giữa khung hình để quét</span>';

            const constraints = {
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            };
            qrScanStream = await navigator.mediaDevices.getUserMedia(constraints);
            video.srcObject = qrScanStream;
            video.setAttribute('playsinline', 'true');
            await video.play();
            isQrScanning = true;

            const barcodeDetector = ('BarcodeDetector' in window)
                ? new BarcodeDetector({ formats: ['qr_code'] })
                : null;

            const ctx = canvas.getContext('2d', { willReadFrequently: true });

            const scanTick = async () => {
                if (!isQrScanning) return;
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                    let detectedValue = null;

                    if (barcodeDetector) {
                        try {
                            const barcodes = await barcodeDetector.detect(video);
                            if (barcodes.length > 0 && barcodes[0].rawValue) {
                                detectedValue = barcodes[0].rawValue;
                            }
                        } catch (e) {}
                    }

                    if (!detectedValue && window.jsQR) {
                        try {
                            const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                            const qrCode = window.jsQR(imgData.data, imgData.width, imgData.height, {
                                inversionAttempts: 'dontInvert'
                            });
                            if (qrCode && qrCode.data) {
                                detectedValue = qrCode.data;
                            }
                        } catch (e) {}
                    }

                    if (detectedValue) {
                        isQrScanning = false;
                        if (feedback) feedback.innerHTML = '<span class="text-emerald-500 font-bold">✔ Đã tìm thấy mã QR! Đang xác thực...</span>';
                        stopQrCamera();
                        submitQrCheckin(detectedValue, activeScanAssignmentId);
                        return;
                    }
                }
                qrScanAnimFrame = requestAnimationFrame(scanTick);
            };
            qrScanAnimFrame = requestAnimationFrame(scanTick);
        } catch (err) {
            if (placeholder) placeholder.classList.remove('hidden');
            if (reticle) reticle.classList.add('hidden');
            if (feedback) {
                feedback.innerHTML = `<span class="text-rose-500 font-bold">Không thể mở camera: ${err.message || 'Chưa cấp quyền'}. Bạn có thể Tải ảnh hoặc Nhập mã token thủ công.</span>`;
            }
        }
    }

    function stopQrCamera() {
        isQrScanning = false;
        if (qrScanAnimFrame) {
            cancelAnimationFrame(qrScanAnimFrame);
            qrScanAnimFrame = null;
        }
        if (qrScanStream) {
            qrScanStream.getTracks().forEach(t => t.stop());
            qrScanStream = null;
        }
        const video = document.getElementById('qr-scanner-video');
        if (video) video.srcObject = null;
        const reticle = document.getElementById('qr-viewfinder-reticle');
        if (reticle) reticle.classList.add('hidden');
        const placeholder = document.getElementById('qr-camera-placeholder');
        if (placeholder) placeholder.classList.remove('hidden');
        const btnStop = document.getElementById('btn-stop-camera');
        if (btnStop) btnStop.classList.add('hidden');
    }

    const btnStartCam = document.getElementById('btn-start-camera');
    if (btnStartCam) btnStartCam.addEventListener('click', startQrCamera);

    const btnStopCam = document.getElementById('btn-stop-camera');
    if (btnStopCam) btnStopCam.addEventListener('click', stopQrCamera);

    // Xử lý tải ảnh QR từ máy
    const qrFileInput = document.getElementById('qr-file-input');
    const qrDropzone = document.getElementById('qr-dropzone');
    const btnChooseFile = document.getElementById('btn-choose-file');
    if (btnChooseFile && qrFileInput) {
        btnChooseFile.addEventListener('click', () => qrFileInput.click());
    }
    if (qrDropzone && qrFileInput) {
        qrDropzone.addEventListener('click', (e) => {
            if (e.target !== btnChooseFile) qrFileInput.click();
        });
        qrDropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            qrDropzone.classList.add('border-emerald-500', 'bg-emerald-50/20');
        });
        qrDropzone.addEventListener('dragleave', () => {
            qrDropzone.classList.remove('border-emerald-500', 'bg-emerald-50/20');
        });
        qrDropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            qrDropzone.classList.remove('border-emerald-500', 'bg-emerald-50/20');
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                processQrImageFile(e.dataTransfer.files[0]);
            }
        });
    }
    if (qrFileInput) {
        qrFileInput.addEventListener('change', () => {
            if (qrFileInput.files && qrFileInput.files[0]) {
                processQrImageFile(qrFileInput.files[0]);
            }
        });
    }

    function processQrImageFile(file) {
        const previewBox = document.getElementById('qr-file-preview-box');
        const previewImg = document.getElementById('qr-file-preview');
        const nameEl = document.getElementById('qr-file-name');
        const statusEl = document.getElementById('qr-file-status');
        if (!file) return;

        if (previewBox) previewBox.classList.remove('hidden');
        if (nameEl) nameEl.textContent = file.name;
        if (statusEl) statusEl.textContent = 'Đang phân tích ảnh...';

        const reader = new FileReader();
        reader.onload = (e) => {
            const dataUrl = e.target.result;
            if (previewImg) previewImg.src = dataUrl;

            const img = new Image();
            img.onload = () => {
                const maxDim = 900;
                const scale = Math.min(1, maxDim / Math.max(img.width || 1, img.height || 1));
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(img.width * scale));
                canvas.height = Math.max(1, Math.round(img.height * scale));
                const ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                let decodedVal = null;
                if (window.jsQR) {
                    try {
                        const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        const code = window.jsQR(imgData.data, imgData.width, imgData.height, { inversionAttempts: 'attemptBoth' });
                        if (code && code.data) decodedVal = code.data;
                    } catch (err) {}
                }

                if (decodedVal) {
                    if (statusEl) statusEl.innerHTML = '<span class="text-emerald-500 font-bold">✔ Đã giải mã thành công!</span>';
                    submitQrCheckin(decodedVal, activeScanAssignmentId);
                } else {
                    if (statusEl) statusEl.innerHTML = '<span class="text-rose-500 font-bold">Không tìm thấy mã QR trong ảnh. Vui lòng thử ảnh rõ nét hơn.</span>';
                    showQrResultBox(false, 'Không giải mã được mã QR trong ảnh đã tải lên. Hãy dùng nút <strong>Tải ảnh PNG</strong> trên Máy chiếu QR hoặc chụp rõ toàn bộ 4 góc mã QR.');
                }
            };
            img.src = dataUrl;
        };
        reader.readAsDataURL(file);
    }

    // Nhập mã Token / URL thủ công
    const btnSubmitManual = document.getElementById('btn-submit-manual-qr');
    if (btnSubmitManual) {
        btnSubmitManual.addEventListener('click', () => {
            const inp = document.getElementById('qr-manual-input');
            const val = inp ? inp.value.trim() : '';
            if (!val) {
                XanhHubUI.toast('warning', 'Chưa nhập mã', 'Vui lòng nhập chuỗi token hoặc URL mã QR.');
                return;
            }
            submitQrCheckin(val, activeScanAssignmentId);
        });
    }

    // Nút Điểm danh Nhanh Dự phòng
    const btnQuickCheckin = document.getElementById('btn-quick-checkin-fallback');
    if (btnQuickCheckin) {
        btnQuickCheckin.addEventListener('click', () => {
            submitQrCheckin('QUICK_CHECKIN', activeScanAssignmentId);
        });
    }

    // =========================================================================
    // 9. TRỢ LÝ AI A1: BÓC TÁCH CÂU TIẾNG VIỆT THÀNH BIỂU MẪU CA TRỰC
    // =========================================================================
    const btnAiParse = document.getElementById('btn-ai-parse-shift');
    if (btnAiParse) {
        btnAiParse.addEventListener('click', () => {
            const text = document.getElementById('input-ai-shift-prompt')?.value || '';
            if (!text.trim()) return;

            fetch('?action=api_ai_parse_shift', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ prompt: text }).toString()
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.ok || !res.draft) return;
                    const d = res.draft;
                    const setVal = (id, val) => {
                        const el = document.getElementById(id);
                        if (el && val !== undefined && val !== null) el.value = val;
                    };
                    setVal('shift-form-title', d.title);
                    setVal('shift-form-zone', d.zone);
                    setVal('shift-form-date', d.date_ymd);
                    setVal('shift-form-start', d.start_hm);
                    setVal('shift-form-end', d.end_hm);
                    setVal('shift-form-min', d.min_people);
                    setVal('shift-form-max', d.max_people);
                    if (d.skills && d.skills[0]) {
                        setVal('shift-form-skill', d.skills[0].skill_code);
                    }
                    XanhHubUI.toast('success', 'Đã tự động điền thông tin ca trực', `Đã điền tự động thông tin ca "${d.title}" vào biểu mẫu.`);
                });
        });
    }

    // =========================================================================
    // 10. CẬP NHẬT LỊCH RẢNH ĐỊNH KỲ & NGÀY CỤ THỂ CỦA TÌNH NGUYỆN VIÊN (CN1/CN2)
    // =========================================================================
    const syncAvailMatrixBadge = (cb) => {
        const label = cb.closest('.avail-matrix-cell');
        const textSpan = label ? label.querySelector('.avail-cell-text') : null;
        if (!label || !textSpan) return;
        if (cb.checked) {
            label.className = 'avail-matrix-cell inline-flex items-center justify-center w-full py-1.5 px-2 rounded-lg border cursor-pointer select-none transition-all bg-emerald-50 dark:bg-emerald-950/50 border-emerald-400 dark:border-emerald-700 text-emerald-700 dark:text-emerald-300 font-bold shadow-xs';
            textSpan.textContent = '✓ Rảnh';
        } else {
            label.className = 'avail-matrix-cell inline-flex items-center justify-center w-full py-1.5 px-2 rounded-lg border cursor-pointer select-none transition-all bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 text-slate-400 hover:border-slate-300';
            textSpan.textContent = '— Bận';
        }
    };

    const availCheckboxes = document.querySelectorAll('.avail-matrix-checkbox');
    if (availCheckboxes.length > 0) {
        availCheckboxes.forEach(cb => {
            cb.addEventListener('change', () => syncAvailMatrixBadge(cb));
        });

        const btnPresetWeekend = document.getElementById('btn-avail-preset-weekend');
        if (btnPresetWeekend) {
            btnPresetWeekend.addEventListener('click', () => {
                availCheckboxes.forEach(cb => {
                    const val = cb.value || '';
                    cb.checked = val.startsWith('6_') || val.startsWith('7_');
                    syncAvailMatrixBadge(cb);
                });
            });
        }

        const btnPresetAll = document.getElementById('btn-avail-preset-all');
        if (btnPresetAll) {
            btnPresetAll.addEventListener('click', () => {
                availCheckboxes.forEach(cb => {
                    cb.checked = true;
                    syncAvailMatrixBadge(cb);
                });
            });
        }

        const btnPresetClear = document.getElementById('btn-avail-preset-clear');
        if (btnPresetClear) {
            btnPresetClear.addEventListener('click', () => {
                availCheckboxes.forEach(cb => {
                    cb.checked = false;
                    syncAvailMatrixBadge(cb);
                });
            });
        }
    }

    const btnAddAvail = document.getElementById('btn-save-availability');
    if (btnAddAvail) {
        btnAddAvail.addEventListener('click', (e) => {
            e.preventDefault();
            const volId = btnAddAvail.dataset.volunteerId;
            const overrideDate = document.getElementById('avail-date-input')?.value || '';
            const day = document.getElementById('avail-day-select')?.value || '6';
            const startHm = document.getElementById('avail-start-input')?.value || '07:00';
            const endHm = document.getElementById('avail-end-input')?.value || '18:00';
            const isAvail = document.getElementById('avail-status-select')?.value ?? '1';

            fetch('?action=api_update_availability', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    volunteer_id: volId,
                    override_date: overrideDate,
                    day_of_week: day,
                    start_hm: startHm,
                    end_hm: endHm,
                    is_available: isAvail
                }).toString()
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        XanhHubUI.toast('error', 'Lỗi cập nhật lịch rảnh', res.message_vi);
                        return;
                    }
                    XanhHubUI.toast('success', 'Đã cập nhật lịch rảnh', res.message_vi);
                    const url = new URL(window.location.href);
                    url.searchParams.set('vtab', 'vtab-profile');
                    sessionStorage.setItem('xanhhub_active_tab_volunteer', 'vtab-profile');
                    setTimeout(() => {
                        window.location.href = url.toString();
                    }, 500);
                });
        });
    }

    // =========================================================================
    // 11. BỘ TỔNG HỢP ÂM THANH MÔ PHỎNG ĐIỆN ẢNH (WEB AUDIO AMBIENT SYNTH)
    // Âm hưởng Thanh niên: Khúc dạo Acoustic/Piano ấm áp, âm lượng dịu nhẹ (~12%)
    // =========================================================================
    const AmbientSynth = (function () {
        let audioCtx = null;
        let masterGain = null;
        let isPlaying = false;
        let timerId = null;
        let step = 0;

        // Tiến trình hợp âm Thanh niên tươi sáng: Cmaj7 -> Gmaj -> Am7 -> Fmaj7
        const chordProgression = [
            // Cmaj7
            [261.63, 329.63, 392.00, 493.88, 523.25],
            // G
            [196.00, 246.94, 293.66, 392.00, 493.88],
            // Am7
            [220.00, 261.63, 329.63, 392.00, 440.00],
            // Fmaj7
            [174.61, 220.00, 261.63, 329.63, 392.00]
        ];

        function initContext() {
            if (audioCtx) return;
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;
            audioCtx = new AudioContextClass();

            // Master Gain khống chế mức âm thanh dịu nhẹ (~0.12)
            masterGain = audioCtx.createGain();
            masterGain.gain.setValueAtTime(0.12, audioCtx.currentTime);

            // Biquad Filter tạo âm sắc ấm áp như đàn mộc acoustic
            const filter = audioCtx.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.setValueAtTime(1200, audioCtx.currentTime);
            filter.Q.setValueAtTime(1.2, audioCtx.currentTime);

            // Delay / Reverb giả lập không gian vòm điện ảnh
            const delay = audioCtx.createDelay();
            delay.delayTime.setValueAtTime(0.36, audioCtx.currentTime);
            const feedback = audioCtx.createGain();
            feedback.gain.setValueAtTime(0.28, audioCtx.currentTime);

            delay.connect(feedback);
            feedback.connect(delay);

            masterGain.connect(filter);
            filter.connect(audioCtx.destination);
            filter.connect(delay);
            delay.connect(audioCtx.destination);
        }

        function playTone(freq, duration = 1.6) {
            if (!audioCtx || !isPlaying) return;
            try {
                const now = audioCtx.currentTime;
                const osc = audioCtx.createOscillator();
                const noteGain = audioCtx.createGain();

                // Dạng sóng hình tam giác/sine pha trộn tạo âm piano/guitar mộc
                osc.type = (step % 2 === 0) ? 'triangle' : 'sine';
                osc.frequency.setValueAtTime(freq, now);

                // Đường bao ADSR: Chạm nhẹ (attack 0.03s), ngân vang và tắt dần mượt
                noteGain.gain.setValueAtTime(0.001, now);
                noteGain.gain.exponentialRampToValueAtTime(0.22, now + 0.04);
                noteGain.gain.exponentialRampToValueAtTime(0.0001, now + duration);

                osc.connect(noteGain);
                noteGain.connect(masterGain);

                osc.start(now);
                osc.stop(now + duration + 0.05);
            } catch (err) {
                // Xử lý an toàn khi AudioContext bị hạn chế bởi trình duyệt
            }
        }

        function tick() {
            if (!isPlaying) return;
            const chordIndex = Math.floor(step / 4) % chordProgression.length;
            const noteIndex = step % chordProgression[chordIndex].length;
            const freq = chordProgression[chordIndex][noteIndex];

            playTone(freq, 1.8);
            step = (step + 1) % (chordProgression.length * 4);

            timerId = setTimeout(tick, 450);
        }

        function start() {
            initContext();
            if (!audioCtx) return;
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            isPlaying = true;
            step = 0;
            tick();
        }

        function stop() {
            isPlaying = false;
            if (timerId) {
                clearTimeout(timerId);
                timerId = null;
            }
        }

        function toggle() {
            if (isPlaying) {
                stop();
                return false;
            } else {
                start();
                return true;
            }
        }

        return { toggle, isPlaying: () => isPlaying, start, stop };
    })();

    // Gắn sự kiện cho nút Floating Audio Pill
    const audioPill = document.getElementById('floating-audio-pill');
    if (audioPill) {
        audioPill.addEventListener('click', () => {
            const active = AmbientSynth.toggle();
            audioPill.classList.toggle('is-playing', active);
            const labelEl = audioPill.querySelector('.audio-text');
            if (labelEl) {
                labelEl.textContent = active ? 'Đang phát: Âm hưởng Xanh' : 'Âm hưởng Thanh niên';
            }
            if (active) {
                XanhHubUI.toast('info', 'Âm hưởng Thanh niên', 'Đang phát giai điệu mộc mạc tiếp thêm nhiệt huyết tuổi trẻ.');
            }
        });
    }

    // =========================================================================
    // 12. CON TRỎ CHUỘT CINEMATIC CUSTOM CURSOR (CHO DESKTOP)
    // =========================================================================
    const cursorDot = document.getElementById('cursor-dot');
    const cursorCircle = document.getElementById('cursor-circle');

    if (cursorDot && cursorCircle && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        let mouseX = -100;
        let mouseY = -100;
        let circleX = -100;
        let circleY = -100;

        window.addEventListener('mousemove', (e) => {
            mouseX = e.clientX;
            mouseY = e.clientY;
            cursorDot.style.transform = `translate3d(${mouseX}px, ${mouseY}px, 0)`;
        });

        // Vòng tròn bám theo mượt mà (Lerp animation loop)
        const renderCursor = () => {
            circleX += (mouseX - circleX) * 0.18;
            circleY += (mouseY - circleY) * 0.18;
            cursorCircle.style.transform = `translate3d(${circleX}px, ${circleY}px, 0)`;
            requestAnimationFrame(renderCursor);
        };
        requestAnimationFrame(renderCursor);

        // Hiệu ứng phóng to nam châm khi di chuột qua phần tử tương tác
        const interactiveSelector = 'a, button, [data-tilt-card], input, select, textarea, .tab-btn, .clickable-shift-row';
        document.addEventListener('mouseover', (e) => {
            if (e.target.closest(interactiveSelector)) {
                document.body.classList.add('cursor-hover');
            }
        });
        document.addEventListener('mouseout', (e) => {
            if (e.target.closest(interactiveSelector)) {
                document.body.classList.remove('cursor-hover');
            }
        });

        // Ẩn con trỏ khi rời khỏi cửa sổ
        document.addEventListener('mouseleave', () => {
            cursorDot.style.opacity = '0';
            cursorCircle.style.opacity = '0';
        });
        document.addEventListener('mouseenter', () => {
            cursorDot.style.opacity = '1';
            cursorCircle.style.opacity = '1';
        });
    }

    // =========================================================================
    // 13. HIỆU ỨNG THẺ NGHIÊNG 3D (3D TILT CARDS)
    // =========================================================================
    const tiltCards = document.querySelectorAll('[data-tilt-card]');
    tiltCards.forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const rotateX = ((y - centerY) / centerY) * -7;
            const rotateY = ((x - centerX) / centerX) * 7;
            card.style.transform = `perspective(1000px) rotateX(${rotateX.toFixed(2)}deg) rotateY(${rotateY.toFixed(2)}deg) scale3d(1.015, 1.015, 1.015)`;
        });
        card.addEventListener('mouseleave', () => {
            card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) scale3d(1, 1, 1)';
        });
    });

    // =========================================================================
    // 14. CHUYỂN TRANG MƯỢT MÀ VỚI RÈM MỜ (PAGE TRANSITION CURTAIN)
    // =========================================================================
    const pageCurtain = document.getElementById('page-curtain');
    if (pageCurtain) {
        // Tắt rèm khi trang tải xong
        window.addEventListener('pageshow', () => {
            pageCurtain.classList.remove('is-transitioning');
        });

        document.addEventListener('click', (e) => {
            const anchor = e.target.closest('a');
            if (!anchor) return;
            const href = anchor.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:') || anchor.target === '_blank' || e.ctrlKey || e.metaKey) {
                return;
            }

            // Chỉ kích hoạt với các liên kết nội bộ trong ứng dụng Xanh Hub
            if (href.startsWith('?') || href.startsWith('/') || href.includes('index.php')) {
                e.preventDefault();
                pageCurtain.classList.add('is-transitioning');
                setTimeout(() => {
                    window.location.href = anchor.href;
                }, 160);
            }
        });
    }

    // =========================================================================
    // 15. ĐIỀU HƯỚNG PHIM CHIẾN DỊCH (VIDEO CHAPTER SEEKING)
    // =========================================================================
    const chapterButtons = document.querySelectorAll('[data-seek-video]');
    chapterButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetSelector = btn.dataset.targetVideo || '#hero-cinema-video';
            const videoEl = document.querySelector(targetSelector);
            const seconds = parseFloat(btn.dataset.seekVideo || '0');
            if (videoEl) {
                videoEl.currentTime = seconds;
                if (videoEl.paused) {
                    videoEl.play().catch(() => {});
                }
                chapterButtons.forEach(b => b.classList.remove('border-sky-400', 'bg-sky-500/20'));
                btn.classList.add('border-sky-400', 'bg-sky-500/20');
            }
        });
    });

    // Xử lý mở Modal Cinema Lightbox
    const cinemaModalTrigger = document.querySelector('[data-open-cinema-modal]');
    const cinemaModal = document.getElementById('modal-cinema-video');
    const cinemaPlayer = document.getElementById('modal-cinema-player');
    if (cinemaModalTrigger && cinemaModal && cinemaPlayer) {
        cinemaModalTrigger.addEventListener('click', () => {
            cinemaModal.classList.add('open');
            cinemaPlayer.currentTime = 0;
            cinemaPlayer.play().catch(() => {});
        });
        const closeBtn = cinemaModal.querySelector('[data-close-modal="modal-cinema-video"]');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                cinemaPlayer.pause();
            });
        }
    }

    // =========================================================================
    // 16. TÁI THIẾT KẾ BÀN ĐIỀU PHỐI MASTER-DETAIL, DATAGRID, CANDIDATE INSPECTOR & 2-STEP CREATE ACTIVITY (R1 & R4)
    // =========================================================================

    // 16.1. Khởi tạo dữ liệu Điều phối từ JSON Payload
    const coordinatorPayloadEl = document.getElementById('coordinator-data-payload');
    let coordinatorData = null;
    if (coordinatorPayloadEl) {
        try {
            coordinatorData = JSON.parse(coordinatorPayloadEl.textContent || '{}');
        } catch (e) {
            console.error('Lỗi phân tích cú pháp coordinator-data-payload:', e);
        }
    }

    if (coordinatorData && coordinatorData.shifts) {
        const shiftsMap = {};
        coordinatorData.shifts.forEach(s => { shiftsMap[s.id] = s; });

        let activeShiftId = coordinatorData.shifts[0]?.id || null;
        let eligiblePage = 1;
        const eligiblePageSize = 5;

        // Render Active Shift Detail Inspector
        function renderActiveShift(shiftId) {
            const shift = shiftsMap[shiftId];
            if (!shift) return;
            activeShiftId = shiftId;

            // Highlight active compact card
            document.querySelectorAll('.compact-shift-card').forEach(card => {
                const isActive = parseInt(card.dataset.shiftId, 10) === parseInt(shiftId, 10);
                card.classList.toggle('active', isActive);
                card.classList.toggle('border-[#0057B8]', isActive);
                card.classList.toggle('dark:border-sky-400', isActive);
                card.classList.toggle('bg-blue-50/40', isActive);
                card.classList.toggle('dark:bg-sky-950/30', isActive);
                card.classList.toggle('ring-2', isActive);
                card.classList.toggle('ring-[#0057B8]/20', isActive);
                card.classList.toggle('shadow-sm', isActive);
                card.classList.toggle('border-slate-200', !isActive);
                card.classList.toggle('dark:border-slate-800', !isActive);
                card.classList.toggle('bg-white', !isActive);
                card.classList.toggle('dark:bg-[#131822]', !isActive);
            });

            // Update Inspector Header
            const codeEl = document.getElementById('insp-shift-code');
            const titleEl = document.getElementById('insp-shift-title');
            const timeEl = document.getElementById('insp-shift-time');
            const locEl = document.getElementById('insp-shift-location');
            const skillEl = document.getElementById('insp-shift-skill');
            const assignedCountEl = document.getElementById('insp-assigned-count');
            const capacityRangeEl = document.getElementById('insp-capacity-range');
            const statusBadgeEl = document.getElementById('insp-shift-status-badge');
            const lockBadgeEl = document.getElementById('insp-shift-lock-badge');

            if (codeEl) codeEl.textContent = `[${shift.code}]`;
            if (titleEl) titleEl.textContent = shift.title;
            if (timeEl) timeEl.textContent = shift.time_fmt;
            if (locEl) locEl.textContent = shift.location_name;
            if (skillEl) skillEl.textContent = `${shift.req_skill_name} ≥ Cấp ${shift.req_min_level}`;
            if (assignedCountEl) assignedCountEl.textContent = shift.assigned_count;
            if (capacityRangeEl) capacityRangeEl.textContent = `${shift.min_people}–${shift.max_people}`;

            const formShiftIdEl = document.getElementById('insp-form-shift-id');
            const formMinEl = document.getElementById('insp-form-min-people');
            const formMaxEl = document.getElementById('insp-form-max-people');
            const formSkillEl = document.getElementById('insp-form-skill-code');
            const formLvlEl = document.getElementById('insp-form-min-level');
            if (formShiftIdEl) formShiftIdEl.value = shift.id;
            if (formMinEl) formMinEl.value = shift.min_people;
            if (formMaxEl) formMaxEl.value = shift.max_people;
            if (formSkillEl && shift.req_skill_code) formSkillEl.value = shift.req_skill_code;
            if (formLvlEl && shift.req_min_level) formLvlEl.value = shift.req_min_level;

            if (statusBadgeEl) {
                const isUnder = shift.assigned_count < shift.min_people;
                const isFull = shift.assigned_count >= shift.max_people;
                statusBadgeEl.className = `badge ${isFull ? 'badge-valid' : (isUnder ? 'badge-violation' : 'badge-info')}`;
                statusBadgeEl.textContent = shift.is_locked ? 'Đã khóa' : (isFull ? 'Đủ người' : (isUnder ? 'Thiếu người' : 'Đang điều phối'));
            }
            if (lockBadgeEl) {
                lockBadgeEl.classList.toggle('hidden', !shift.is_locked);
            }

            renderAssignedRoster(shift);
            eligiblePage = 1;
            renderEligiblePool(shift);
            renderReplacementTab(shift);
        }

        // Render Zone 1: Assigned Roster + Pending Registrations (Tab 1 - 100% Width, Full Unclipped Names)
        function renderAssignedRoster(shift) {
            const listEl = document.getElementById('assigned-roster-list');
            const countBadge = document.getElementById('badge-assigned-roster-count');
            const query = (document.getElementById('search-assigned-roster')?.value || '').toLowerCase().trim();
            if (!listEl) return;

            let list = shift.assignments || [];
            const assignedVolIds = list.map(a => a.volunteer_id);
            const regsMap = shift.registrations || {};

            // Tìm các tình nguyện viên đã đăng ký ca này nhưng chưa được phân công (Chờ duyệt)
            const pendingRegisteredVols = (coordinatorData.volunteers || []).filter(v => {
                if (assignedVolIds.includes(v.id)) return false;
                return regsMap[v.id] !== undefined || regsMap[String(v.id)] !== undefined;
            });

            if (countBadge) {
                countBadge.textContent = pendingRegisteredVols.length > 0
                    ? `${list.length} người (${pendingRegisteredVols.length} chờ duyệt)`
                    : `${list.length} người`;
            }

            if (query) {
                list = list.filter(item => {
                    return (item.full_name || '').toLowerCase().includes(query) ||
                           (item.student_code || '').toLowerCase().includes(query) ||
                           (item.chapter_name || '').toLowerCase().includes(query);
                });
            }

            let pendingHtml = '';
            if (pendingRegisteredVols.length > 0) {
                const pendingItemsHtml = pendingRegisteredVols.map(v => {
                    const prefRank = regsMap[v.id] || regsMap[String(v.id)] || 1;
                    const reqSkill = (v.skills || []).find(s => s.code === shift.req_skill_code && s.level >= shift.req_min_level);
                    const skillStatusBadge = reqSkill
                        ? `<span class="badge badge-valid text-[10px] whitespace-nowrap shrink-0">Đủ kỹ năng: ${reqSkill.name} (Cấp ${reqSkill.level})</span>`
                        : `<span class="badge badge-violation text-[10px] whitespace-nowrap shrink-0">Thiếu kỹ năng: ${shift.req_skill_name} ≥ Cấp ${shift.req_min_level}</span>`;

                    return `
                        <div class="p-3 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-800 flex flex-wrap items-center justify-between gap-2.5 w-full">
                            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                <img src="${v.avatar_url}" alt="" class="w-8 h-8 rounded-full object-cover ring-2 ring-amber-400/50 shrink-0">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="font-bold text-xs sm:text-sm text-slate-900 dark:text-white cursor-pointer hover:text-[#0057B8] btn-inspect-candidate" data-volunteer-id="${v.id}">${v.full_name}</span>
                                        <span class="mono text-[11px] text-slate-400 shrink-0">(${v.student_code})</span>
                                        <span class="badge badge-warning text-[10px] whitespace-nowrap shrink-0">Đã đăng ký Nguyện vọng ${prefRank} · Chờ duyệt</span>
                                        ${skillStatusBadge}
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5 truncate">${v.chapter_name}</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                                <button type="button" class="btn btn-sm btn-outline btn-inspect-candidate px-2 py-1 text-[11px] text-[#0057B8] dark:text-sky-400" data-volunteer-id="${v.id}">
                                    <span class="material-symbols-outlined text-[14px]">visibility</span>
                                    <span>Hồ sơ</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-success btn-approve-reg-shift px-3 py-1 text-xs font-bold flex items-center gap-1 whitespace-nowrap"
                                        data-volunteer-id="${v.id}"
                                        data-shift-id="${shift.id}"
                                        data-req-id="${shift.req_id || ''}">
                                    <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                    <span>✓ Duyệt đăng ký (Gán vào ca)</span>
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');

                pendingHtml = `
                    <div class="mb-3 p-3 rounded-xl bg-amber-50/40 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/70 space-y-2">
                        <div class="text-xs font-extrabold text-amber-800 dark:text-amber-300 flex items-center justify-between">
                            <span class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[16px]">pending_actions</span>
                                <span>Đơn Đăng ký Nguyện vọng Chờ Duyệt vào Ca (${pendingRegisteredVols.length} TNV)</span>
                            </span>
                            <span class="text-[11px] font-normal text-slate-500">Bấm "Duyệt đăng ký" để kiểm tra điều kiện &amp; phân công</span>
                        </div>
                        ${pendingItemsHtml}
                    </div>
                `;
            }

            if (list.length === 0) {
                listEl.innerHTML = pendingHtml + `
                    <div class="p-4 border border-dashed border-slate-300 dark:border-slate-700 rounded-xl text-center text-xs text-slate-400">
                        ${query ? 'Không tìm thấy nhân sự phù hợp bộ lọc.' : 'Chưa có nhân sự nào được chốt phân công trong ca trực này. Bạn có thể bấm "✓ Duyệt đăng ký" ở trên, chuyển sang Tab "Duyệt Đăng ký & Ứng viên Gợi ý", hoặc bấm nút "Tự động Đề xuất & Duyệt Phân công".'}
                    </div>
                `;
                listEl.querySelectorAll('.btn-approve-reg-shift').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const volId = btn.dataset.volunteerId;
                        const shiftId = btn.dataset.shiftId;
                        const reqId = btn.dataset.reqId || '';
                        const actId = coordinatorData.activity_id || '3';
                        const targetCard = document.querySelector(`.compact-shift-card[data-shift-id="${shiftId}"]`);
                        submitManualAssignment(actId, shiftId, volId, reqId, '', targetCard);
                    });
                });
                listEl.querySelectorAll('.btn-inspect-candidate').forEach(el => {
                    el.addEventListener('click', () => {
                        const vid = el.dataset.volunteerId;
                        if (vid) openCandidateInspector(vid);
                    });
                });
                return;
            }

            listEl.innerHTML = pendingHtml + list.map(item => `
                <div class="assigned-pill p-3 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex flex-wrap items-center justify-between gap-2.5 transition-all hover:border-[#0057B8] w-full">
                    <div class="flex items-center gap-2.5 min-w-0 flex-1">
                        <img src="${item.avatar_url}" alt="" class="w-8 h-8 rounded-full object-cover ring-2 ring-[#0057B8]/30 shrink-0">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-bold text-xs sm:text-sm text-slate-900 dark:text-white cursor-pointer hover:text-[#0057B8] btn-inspect-candidate truncate" data-volunteer-id="${item.volunteer_id}">${item.full_name}</span>
                                <span class="mono text-[11px] text-slate-400 shrink-0">(${item.student_code})</span>
                                <span class="badge badge-valid text-[10px] whitespace-nowrap shrink-0">Đã xác nhận</span>
                            </div>
                            <div class="text-[11px] text-slate-500 mt-0.5 truncate">${item.chapter_name}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                        <button type="button" class="btn btn-sm btn-outline btn-inspect-candidate px-2 py-1 text-[11px] text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-1 whitespace-nowrap" data-volunteer-id="${item.volunteer_id}" title="Xem Hồ sơ Ứng viên">
                            <span class="material-symbols-outlined text-[14px]">visibility</span>
                            <span>Hồ sơ</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline btn-open-eval-volunteer px-2 py-1 text-[11px] text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/40 flex items-center gap-1 whitespace-nowrap"
                                data-assignment-id="${item.id}"
                                data-volunteer-id="${item.volunteer_id}"
                                data-shift-id="${shift.id}"
                                data-volunteer-name="${item.full_name}"
                                data-open-modal="modal-eval-volunteer"
                                title="Đánh giá 360°">
                            <span class="material-symbols-outlined text-[14px]">hotel_class</span>
                            <span>Đánh giá</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline btn-open-absence px-2 py-1 text-[11px] text-sky-600 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-950/40 flex items-center gap-1 whitespace-nowrap" data-assignment-id="${item.id}" title="Báo vắng &amp; Thay người">
                            <span class="material-symbols-outlined text-[14px]">published_with_changes</span>
                            <span>Thay người</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline btn-remove-assign px-2 py-1 text-[11px] text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 flex items-center gap-1 whitespace-nowrap" data-assignment-id="${item.id}" title="Gỡ khỏi ca">
                            <span class="material-symbols-outlined text-[14px]">close</span>
                            <span>Gỡ</span>
                        </button>
                    </div>
                </div>
            `).join('');

            // Attach dynamic approve registration handlers in Tab 1
            listEl.querySelectorAll('.btn-approve-reg-shift').forEach(btn => {
                btn.addEventListener('click', () => {
                    const volId = btn.dataset.volunteerId;
                    const shiftId = btn.dataset.shiftId;
                    const reqId = btn.dataset.reqId || '';
                    const actId = coordinatorData.activity_id || '3';
                    const targetCard = document.querySelector(`.compact-shift-card[data-shift-id="${shiftId}"]`);
                    submitManualAssignment(actId, shiftId, volId, reqId, '', targetCard);
                });
            });

            // Attach dynamic remove assign handlers
            listEl.querySelectorAll('.btn-remove-assign').forEach(btn => {
                btn.addEventListener('click', () => {
                    const assignId = btn.dataset.assignmentId;
                    fetch('?action=api_remove_assignment', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ assignment_id: assignId }).toString()
                    })
                        .then(r => r.json())
                        .then(res => {
                            if (!res.ok) {
                                XanhHubUI.toast('error', 'Không thể gỡ phân công', res.message_vi, res.reason_code ? [res.reason_code] : []);
                                return;
                            }
                            XanhHubUI.toast('success', 'Đã gỡ phân công', res.message_vi);
                            setTimeout(() => window.location.reload(), 550);
                        });
                });
            });
        }

        // Render Zone 2: Eligible & Available Pool (Tab 2 - 100% Width, Full Unclipped Names, Single-Line Badges)
        function renderEligiblePool(shift) {
            const listEl = document.getElementById('eligible-pool-list');
            const countBadge = document.getElementById('badge-eligible-count');
            const prevBtn = document.getElementById('btn-eligible-prev');
            const nextBtn = document.getElementById('btn-eligible-next');
            const pageText = document.getElementById('text-eligible-page');
            const searchVal = (document.getElementById('search-eligible-pool')?.value || '').toLowerCase().trim();
            const skillFilter = document.getElementById('filter-eligible-skill')?.value || '';
            if (!listEl) return;

            const assignedVolIds = (shift.assignments || []).map(a => a.volunteer_id);
            const regsMap = shift.registrations || {};

            // Filter candidates (show all unassigned volunteers; sort registered & skill-matched to top so Coordinator can manually assign anyone)
            let eligible = (coordinatorData.volunteers || []).filter(v => {
                // Must not be already assigned in this shift
                if (assignedVolIds.includes(v.id)) return false;

                // Check text search
                if (searchVal) {
                    const matchName = (v.full_name || '').toLowerCase().includes(searchVal);
                    const matchCode = (v.student_code || '').toLowerCase().includes(searchVal);
                    const matchUnit = (v.chapter_name || '').toLowerCase().includes(searchVal);
                    if (!matchName && !matchCode && !matchUnit) return false;
                }

                // Check skill dropdown filter
                if (skillFilter) {
                    const hasFilterSkill = (v.skills || []).some(s => s.code === skillFilter);
                    if (!hasFilterSkill) return false;
                }

                return true;
            });

            // Sort: registered volunteers first (Nguyện vọng 1 -> 2 -> 3), then skill-matched, then lower current load, then reputation
            eligible.sort((a, b) => {
                const regA = regsMap[a.id] ?? regsMap[String(a.id)] ?? 99;
                const regB = regsMap[b.id] ?? regsMap[String(b.id)] ?? 99;
                if (regA !== regB) return regA - regB;
                const skillA = shift.req_skill_code && (a.skills || []).some(s => s.code === shift.req_skill_code && s.level >= shift.req_min_level) ? 1 : 0;
                const skillB = shift.req_skill_code && (b.skills || []).some(s => s.code === shift.req_skill_code && s.level >= shift.req_min_level) ? 1 : 0;
                if (skillA !== skillB) return skillB - skillA;
                return a.assigned_count - b.assigned_count || (b.reputation || 0) - (a.reputation || 0);
            });

            const total = eligible.length;
            if (countBadge) countBadge.textContent = `${total} ứng viên`;

            const totalPages = Math.max(1, Math.ceil(total / eligiblePageSize));
            if (eligiblePage > totalPages) eligiblePage = totalPages;
            if (eligiblePage < 1) eligiblePage = 1;

            if (pageText) pageText.textContent = `${eligiblePage} / ${totalPages}`;
            if (prevBtn) prevBtn.disabled = (eligiblePage <= 1);
            if (nextBtn) nextBtn.disabled = (eligiblePage >= totalPages);

            const startIdx = (eligiblePage - 1) * eligiblePageSize;
            const paged = eligible.slice(startIdx, startIdx + eligiblePageSize);

            if (paged.length === 0) {
                listEl.innerHTML = `
                    <div class="p-4 border border-dashed border-slate-300 dark:border-slate-700 rounded-xl text-center text-xs text-slate-400">
                        Không có ứng viên nào khớp điều kiện chuyên môn hoặc bộ lọc tìm kiếm.
                    </div>
                `;
                return;
            }

            listEl.innerHTML = paged.map(v => {
                const prefRank = regsMap[v.id] ?? regsMap[String(v.id)] ?? null;
                const reqSkill = (v.skills || []).find(s => s.code === shift.req_skill_code && s.level >= shift.req_min_level);
                const skillBadge = reqSkill
                    ? `<span class="badge badge-info text-[10px] whitespace-nowrap shrink-0">${reqSkill.name} (Cấp ${reqSkill.level})</span>`
                    : `<span class="badge badge-violation text-[10px] whitespace-nowrap shrink-0">Thiếu kỹ năng: ${shift.req_skill_name} ≥ Cấp ${shift.req_min_level}</span>`;
                const regBadge = prefRank !== null
                    ? `<span class="badge badge-warning text-[10px] whitespace-nowrap shrink-0">Đã đăng ký Nguyện vọng ${prefRank}</span>`
                    : '';
                const actionBtnLabel = prefRank !== null ? '✓ Duyệt đăng ký' : '+ Gán vào ca';
                const actionBtnClass = prefRank !== null ? 'btn-success' : 'btn-primary';

                return `
                    <div class="eligible-candidate-card p-3 rounded-xl ${prefRank !== null ? 'bg-amber-50/60 dark:bg-amber-950/20 border-amber-300 dark:border-amber-800' : 'bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700'} border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 transition-all hover:border-[#0057B8] w-full"
                         draggable="true"
                         data-volunteer-id="${v.id}">
                        <div class="flex items-center gap-2.5 min-w-0 flex-1">
                            <span class="material-symbols-outlined text-slate-300 text-[18px] cursor-grab shrink-0" title="Kéo để thả vào Tab Nhân sự">drag_indicator</span>
                            <img src="${v.avatar_url}" alt="" class="w-9 h-9 rounded-full object-cover ring-2 ring-slate-200 dark:ring-slate-700 shrink-0">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold text-xs sm:text-sm text-slate-900 dark:text-white cursor-pointer hover:text-[#0057B8] btn-inspect-candidate" data-volunteer-id="${v.id}">${v.full_name}</span>
                                    <span class="mono text-[11px] text-slate-400 shrink-0">(${v.student_code})</span>
                                    <span class="text-[11px] text-slate-500 hidden sm:inline">&middot; ${v.chapter_name}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    ${regBadge}
                                    ${skillBadge}
                                    <span class="badge badge-subtle text-[10px] mono whitespace-nowrap shrink-0">${v.assigned_count} ca đã nhận</span>
                                    ${v.reputation ? `<span class="badge badge-warning text-[10px] mono whitespace-nowrap shrink-0">★ ${v.reputation}</span>` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 self-end sm:self-center">
                            <button type="button" class="btn btn-sm btn-outline btn-inspect-candidate px-2.5 py-1 text-xs text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-700 flex items-center gap-1" data-volunteer-id="${v.id}" title="Xem hồ sơ">
                                <span class="material-symbols-outlined text-[14px]">visibility</span>
                                <span class="hidden md:inline">Hồ sơ</span>
                            </button>
                            <button type="button" class="btn btn-sm ${actionBtnClass} btn-quick-assign-active-shift px-3 py-1 text-xs flex items-center gap-1.5 font-bold shadow-xs whitespace-nowrap"
                                    data-volunteer-id="${v.id}"
                                    data-shift-id="${shift.id}"
                                    data-req-id="${shift.req_id || ''}"
                                    title="Duyệt đăng ký / Gán 1-chạm vào ca đang mở">
                                <span class="material-symbols-outlined text-[14px]">${prefRank !== null ? 'check_circle' : 'person_add'}</span>
                                <span>${actionBtnLabel}</span>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');

            // Quick assign button handler
            listEl.querySelectorAll('.btn-quick-assign-active-shift').forEach(btn => {
                btn.addEventListener('click', () => {
                    const volId = btn.dataset.volunteerId;
                    const shiftId = btn.dataset.shiftId;
                    const reqId = btn.dataset.reqId || '';
                    const actId = coordinatorData.activity_id || '3';
                    const targetCard = document.querySelector(`.compact-shift-card[data-shift-id="${shiftId}"]`);
                    submitManualAssignment(actId, shiftId, volId, reqId, '', targetCard);
                });
            });

            // Inspect candidate button handler
            listEl.querySelectorAll('.btn-inspect-candidate').forEach(el => {
                el.addEventListener('click', () => {
                    const vid = el.dataset.volunteerId;
                    if (vid) openCandidateInspector(vid);
                });
            });

            // Drag from Zone 2
            listEl.querySelectorAll('.eligible-candidate-card').forEach(card => {
                card.addEventListener('dragstart', (e) => {
                    draggedVolunteerId = card.dataset.volunteerId;
                    card.classList.add('dragging');
                    e.dataTransfer.setData('text/plain', draggedVolunteerId);
                });
                card.addEventListener('dragend', () => {
                    card.classList.remove('dragging');
                });
            });
        }

        // Render Zone 3: Báo vắng & Đề xuất Thay thế (Tab 3 - ReplacementRanker A3 / CN5)
        function renderReplacementTab(shift) {
            const absenceListEl = document.getElementById('inspector-absence-reports-list');
            const replaceListEl = document.getElementById('inspector-replacement-suggestions-list');
            const tabCountBadge = document.getElementById('badge-replacement-tab-count');
            const shiftAbsCountBadge = document.getElementById('badge-shift-absences-count');

            const absences = shift.absence_reports || [];
            const openAbsences = absences.filter(a => a.status === 'OPEN');
            if (tabCountBadge) tabCountBadge.textContent = `${openAbsences.length} yêu cầu`;
            if (shiftAbsCountBadge) shiftAbsCountBadge.textContent = `${absences.length} phiếu`;

            // 1. Render danh sách báo vắng
            if (absenceListEl) {
                if (absences.length === 0) {
                    absenceListEl.innerHTML = `
                        <div class="p-4 border border-dashed border-slate-200 dark:border-slate-700 rounded-xl text-center text-xs text-slate-400 bg-slate-50/50 dark:bg-slate-800/30">
                            <span class="material-symbols-outlined text-emerald-500 text-[24px] mb-1 block">check_circle</span>
                            Ca trực hiện không có trường hợp báo vắng nào. Tình hình quân số ổn định.
                        </div>
                    `;
                } else {
                    absenceListEl.innerHTML = absences.map(a => `
                        <div class="p-3 rounded-xl border ${a.status === 'OPEN' ? 'border-amber-300 dark:border-amber-700 bg-amber-50/40 dark:bg-amber-950/20' : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50'} flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-xs text-slate-900 dark:text-white">${a.absent_name}</span>
                                    <span class="mono text-[10px] text-slate-400">(${a.absent_code})</span>
                                    <span class="badge ${a.status === 'OPEN' ? 'badge-warning' : 'badge-valid'} text-[10px] whitespace-nowrap">
                                        ${a.status === 'OPEN' ? 'Chờ thay thế' : 'Đã thay thế'}
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-600 dark:text-slate-300 mt-0.5">
                                    <strong>Lý do:</strong> ${a.reason} &middot; <span class="text-slate-400 text-[10px]">${a.reported_at}</span>
                                </div>
                                ${a.replacement_name ? `<div class="text-[10px] text-emerald-600 dark:text-emerald-400 mt-0.5">Người thay thế: <strong>${a.replacement_name} (${a.replacement_code})</strong></div>` : ''}
                            </div>
                            ${a.status === 'OPEN' ? `
                                <button type="button" class="btn btn-sm btn-primary py-1 px-2.5 text-[11px] font-bold shrink-0 btn-open-absence" data-assignment-id="${a.assignment_id}">
                                    Chọn người thay &rarr;
                                </button>
                            ` : ''}
                        </div>
                    `).join('');
                }
            }

            // 2. Render danh sách ứng viên thay thế A3
            if (replaceListEl) {
                const candidates = shift.replacement_candidates || [];
                if (candidates.length === 0) {
                    replaceListEl.innerHTML = `
                        <div class="p-4 border border-dashed border-slate-200 dark:border-slate-700 rounded-xl text-center text-xs text-slate-400">
                            Không tìm thấy ứng viên khả dụng không trùng lịch và đủ điều kiện kỹ năng cho ca này.
                        </div>
                    `;
                } else {
                    replaceListEl.innerHTML = candidates.map((c, idx) => `
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 transition-all hover:border-emerald-500">
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                <div class="w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-extrabold text-xs flex items-center justify-center shrink-0">
                                    #${idx + 1}
                                </div>
                                <img src="${c.avatar_url}" alt="" class="w-9 h-9 rounded-full object-cover ring-2 ring-emerald-500/30 shrink-0">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold text-xs sm:text-sm text-slate-900 dark:text-white cursor-pointer hover:text-[#0057B8] btn-inspect-candidate" data-volunteer-id="${c.volunteer_id}">${c.full_name}</span>
                                        <span class="mono text-[11px] text-slate-400 shrink-0">(${c.student_code})</span>
                                        <span class="badge badge-valid text-[10px] mono whitespace-nowrap shrink-0">Điểm: ${c.score}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        ${(c.reasons_vi || []).map(r => `<span class="text-[10px] bg-white dark:bg-slate-700/80 border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded px-1.5 py-0.5 whitespace-nowrap">${r}</span>`).join('')}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0 self-end sm:self-center">
                                <button type="button" class="btn btn-sm btn-outline btn-inspect-candidate px-2.5 py-1 text-xs text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-700" data-volunteer-id="${c.volunteer_id}" title="Xem hồ sơ">
                                    <span class="material-symbols-outlined text-[14px]">visibility</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-primary bg-emerald-600 hover:bg-emerald-700 text-white border-none btn-quick-assign-active-shift px-3 py-1 text-xs flex items-center gap-1 font-bold whitespace-nowrap shadow-xs"
                                        data-volunteer-id="${c.volunteer_id}"
                                        data-shift-id="${shift.id}"
                                        data-req-id="${shift.req_id || ''}"
                                        title="Gán thay thế vào ca này">
                                    <span class="material-symbols-outlined text-[14px]">how_to_reg</span>
                                    <span>Gán thay thế</span>
                                </button>
                            </div>
                        </div>
                    `).join('');

                    // Attach quick assign handlers for replacement suggestions
                    replaceListEl.querySelectorAll('.btn-quick-assign-active-shift').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const volId = btn.dataset.volunteerId;
                            const shiftId = btn.dataset.shiftId;
                            const reqId = btn.dataset.reqId || '';
                            const actId = coordinatorData.activity_id || '3';
                            const targetCard = document.querySelector(`.compact-shift-card[data-shift-id="${shiftId}"]`);
                            submitManualAssignment(actId, shiftId, volId, reqId, 'Phân công thay thế nhân sự báo vắng', targetCard);
                        });
                    });

                    // Inspect candidate button handler
                    replaceListEl.querySelectorAll('.btn-inspect-candidate').forEach(el => {
                        el.addEventListener('click', () => {
                            const vid = el.dataset.volunteerId;
                            if (vid) openCandidateInspector(vid);
                        });
                    });
                }
            }
        }

        // Tab switching logic for Inspector 3 Tabs
        document.querySelectorAll('.inspector-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetSelector = btn.dataset.tabTarget;
                if (!targetSelector) return;

                document.querySelectorAll('.inspector-tab-btn').forEach(b => {
                    const isTarget = (b === btn);
                    b.classList.toggle('active', isTarget);
                    b.classList.toggle('border-[#0057B8]', isTarget);
                    b.classList.toggle('text-[#0057B8]', isTarget);
                    b.classList.toggle('dark:border-sky-400', isTarget);
                    b.classList.toggle('dark:text-sky-400', isTarget);
                    b.classList.toggle('font-bold', isTarget);
                    b.classList.toggle('border-transparent', !isTarget);
                    b.classList.toggle('text-slate-500', !isTarget);
                    b.classList.toggle('font-medium', !isTarget);
                });

                document.querySelectorAll('.inspector-tab-pane').forEach(pane => {
                    const matches = (`#${pane.id}` === targetSelector);
                    pane.classList.toggle('hidden', !matches);
                    pane.classList.toggle('block', matches);
                });
            });
        });

        // Zone 1 Drop Zone for drag-and-drop
        const zoneAssigned = document.getElementById('zone-assigned-roster');
        if (zoneAssigned) {
            zoneAssigned.addEventListener('dragover', (e) => {
                e.preventDefault();
                zoneAssigned.classList.add('ring-2', 'ring-emerald-500/40', 'bg-emerald-50/20');
            });
            zoneAssigned.addEventListener('dragleave', () => {
                zoneAssigned.classList.remove('ring-2', 'ring-emerald-500/40', 'bg-emerald-50/20');
            });
            zoneAssigned.addEventListener('drop', (e) => {
                e.preventDefault();
                zoneAssigned.classList.remove('ring-2', 'ring-emerald-500/40', 'bg-emerald-50/20');
                const volId = e.dataTransfer.getData('text/plain') || draggedVolunteerId;
                if (!volId || !activeShiftId) return;
                const shift = shiftsMap[activeShiftId];
                const actId = coordinatorData.activity_id || '3';
                const targetCard = document.querySelector(`.compact-shift-card[data-shift-id="${activeShiftId}"]`);
                submitManualAssignment(actId, activeShiftId, volId, shift?.req_id || '', '', targetCard);
            });
        }

        // Direct Manual Assignment Toolbar inside Tab 1 (CN4)
        const btnDirectManualAssign = document.getElementById('btn-direct-manual-assign');
        if (btnDirectManualAssign) {
            btnDirectManualAssign.addEventListener('click', () => {
                const volSelect = document.getElementById('manual-assign-volunteer-select');
                const overrideInp = document.getElementById('manual-assign-override-reason');
                const volId = volSelect ? volSelect.value : '';
                const overrideReason = overrideInp ? overrideInp.value.trim() : '';
                if (!volId || !activeShiftId) return;
                const shift = shiftsMap[activeShiftId];
                const actId = coordinatorData.activity_id || '3';
                const targetCard = document.querySelector(`.compact-shift-card[data-shift-id="${activeShiftId}"]`);
                submitManualAssignment(actId, activeShiftId, volId, shift?.req_id || '', overrideReason, targetCard);
            });
        }

        // Compact Shift Card click handler
        document.querySelectorAll('.compact-shift-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.closest('button')) return; // ignore lock/QR button clicks
                const sid = card.dataset.shiftId;
                if (sid) renderActiveShift(sid);
            });
        });

        // Search inputs listeners
        const inspAssignedSearch = document.getElementById('search-assigned-roster');
        if (inspAssignedSearch) {
            inspAssignedSearch.addEventListener('input', () => {
                if (activeShiftId && shiftsMap[activeShiftId]) renderAssignedRoster(shiftsMap[activeShiftId]);
            });
        }

        const inspEligibleSearch = document.getElementById('search-eligible-pool');
        const inspEligibleSkill = document.getElementById('filter-eligible-skill');
        if (inspEligibleSearch) {
            inspEligibleSearch.addEventListener('input', () => {
                eligiblePage = 1;
                if (activeShiftId && shiftsMap[activeShiftId]) renderEligiblePool(shiftsMap[activeShiftId]);
            });
        }
        if (inspEligibleSkill) {
            inspEligibleSkill.addEventListener('change', () => {
                eligiblePage = 1;
                if (activeShiftId && shiftsMap[activeShiftId]) renderEligiblePool(shiftsMap[activeShiftId]);
            });
        }

        // Eligible pagination buttons
        const btnEligPrev = document.getElementById('btn-eligible-prev');
        const btnEligNext = document.getElementById('btn-eligible-next');
        if (btnEligPrev) {
            btnEligPrev.addEventListener('click', () => {
                if (eligiblePage > 1) {
                    eligiblePage--;
                    if (activeShiftId && shiftsMap[activeShiftId]) renderEligiblePool(shiftsMap[activeShiftId]);
                }
            });
        }
        if (btnEligNext) {
            btnEligNext.addEventListener('click', () => {
                eligiblePage++;
                if (activeShiftId && shiftsMap[activeShiftId]) renderEligiblePool(shiftsMap[activeShiftId]);
            });
        }

        // Master Column Filter Pills + Search + Date-Group Header Sync
        let activeCoordShiftFilter = 'ALL';
        const shiftSearchInput = document.getElementById('shift-search-input');

        function syncCoordinatorShiftListFilters() {
            const q = (shiftSearchInput?.value || '').toLowerCase().trim();
            const visibleByDateKey = {};

            document.querySelectorAll('.compact-shift-card').forEach(card => {
                const status = card.dataset.shiftStatus;
                const isLocked = card.dataset.isLocked === '1';
                const s = (card.dataset.search || '').toLowerCase();
                const dateKey = card.dataset.dateKey || '';

                let matchFilter = true;
                if (activeCoordShiftFilter === 'UNDERSTAFFED') matchFilter = (status === 'UNDERSTAFFED');
                else if (activeCoordShiftFilter === 'FULL') matchFilter = (status === 'FULL');
                else if (activeCoordShiftFilter === 'LOCKED') matchFilter = isLocked;

                const matchSearch = !q || s.includes(q);
                const show = matchFilter && matchSearch;
                card.style.display = show ? '' : 'none';

                if (show && dateKey) {
                    visibleByDateKey[dateKey] = (visibleByDateKey[dateKey] || 0) + 1;
                }
            });

            document.querySelectorAll('.coord-date-group-header').forEach(header => {
                const dk = header.dataset.dateKey || '';
                if (!dk) return;
                header.style.display = visibleByDateKey[dk] ? '' : 'none';
            });
        }

        document.querySelectorAll('[data-shift-filter]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('[data-shift-filter]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeCoordShiftFilter = btn.dataset.shiftFilter || 'ALL';
                syncCoordinatorShiftListFilters();
            });
        });

        if (shiftSearchInput) {
            shiftSearchInput.addEventListener('input', syncCoordinatorShiftListFilters);
        }

        // Initial render of first active shift
        if (activeShiftId) {
            renderActiveShift(activeShiftId);
        }

        // =====================================================================
        // 16.2. BẢNG DỮ LIỆU TỔNG THỂ PHÂN TRANG (DATAGRID TAB)
        // =====================================================================
        let dataGridPage = 1;
        let dataGridPageSize = 10;

        function renderDataGrid() {
            const tbody = document.getElementById('datagrid-tbody');
            const infoText = document.getElementById('datagrid-info-text');
            const controls = document.getElementById('datagrid-pagination-controls');
            if (!tbody) return;

            const q = (document.getElementById('datagrid-search-input')?.value || '').toLowerCase().trim();
            const unit = document.getElementById('datagrid-unit-select')?.value || '';
            const skill = document.getElementById('datagrid-skill-select')?.value || '';
            const shiftStatus = document.getElementById('datagrid-shifts-select')?.value || '';

            let list = (coordinatorData.volunteers || []).filter(v => {
                if (q) {
                    const matchQ = (v.full_name || '').toLowerCase().includes(q) ||
                                   (v.student_code || '').toLowerCase().includes(q) ||
                                   (v.email || '').toLowerCase().includes(q) ||
                                   (v.phone || '').toLowerCase().includes(q);
                    if (!matchQ) return false;
                }
                if (unit && !(v.chapter_name || '').includes(unit)) {
                    return false;
                }
                if (skill && !(v.skills || []).some(s => s.code === skill)) {
                    return false;
                }
                if (shiftStatus) {
                    const count = v.assigned_count || 0;
                    if (shiftStatus === 'ZERO' && count !== 0) return false;
                    if (shiftStatus === 'ONE' && count !== 1) return false;
                    if (shiftStatus === 'TWO' && count !== 2) return false;
                    if (shiftStatus === 'THREE_PLUS' && count < 3) return false;
                }
                return true;
            });

            const total = list.length;
            const totalPages = Math.max(1, Math.ceil(total / dataGridPageSize));
            if (dataGridPage > totalPages) dataGridPage = totalPages;
            if (dataGridPage < 1) dataGridPage = 1;

            const startIdx = (dataGridPage - 1) * dataGridPageSize;
            const endIdx = Math.min(total, startIdx + dataGridPageSize);
            const paged = list.slice(startIdx, endIdx);

            if (infoText) {
                infoText.textContent = total > 0
                    ? `Hiển thị ${startIdx + 1} – ${endIdx} trong tổng số ${total} tình nguyện viên`
                    : 'Không tìm thấy tình nguyện viên phù hợp với tiêu chí lọc.';
            }

            // Pagination Controls
            if (controls) {
                let html = `
                    <button type="button" class="btn btn-sm btn-outline py-1 px-2.5 ${dataGridPage <= 1 ? 'opacity-40 pointer-events-none' : ''}" data-dg-page="${dataGridPage - 1}">&laquo; Trước</button>
                `;
                for (let p = 1; p <= totalPages; p++) {
                    if (p === 1 || p === totalPages || (p >= dataGridPage - 1 && p <= dataGridPage + 1)) {
                        html += `
                            <button type="button" class="btn btn-sm ${p === dataGridPage ? 'btn-primary' : 'btn-outline'} py-1 px-2.5 mono" data-dg-page="${p}">${p}</button>
                        `;
                    } else if (p === dataGridPage - 2 || p === dataGridPage + 2) {
                        html += `<span class="px-1 text-slate-400">...</span>`;
                    }
                }
                html += `
                    <button type="button" class="btn btn-sm btn-outline py-1 px-2.5 ${dataGridPage >= totalPages ? 'opacity-40 pointer-events-none' : ''}" data-dg-page="${dataGridPage + 1}">Sau &raquo;</button>
                `;
                controls.innerHTML = html;

                controls.querySelectorAll('[data-dg-page]').forEach(b => {
                    b.addEventListener('click', () => {
                        dataGridPage = parseInt(b.dataset.dgPage, 10);
                        renderDataGrid();
                    });
                });
            }

            if (paged.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="py-8 text-center text-slate-400">
                            Không tìm thấy tình nguyện viên nào khớp với bộ lọc đa tiêu chí.
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = paged.map((v, i) => {
                const skillsHtml = (v.skills || []).map(s => `
                    <span class="badge ${s.verified ? 'badge-valid' : 'badge-info'} text-[10px] inline-flex items-center gap-0.5 mr-1 mb-1">
                        ${s.verified ? '<span class="material-symbols-outlined text-[10px]">check</span>' : ''}
                        <span>${s.name} (C${s.level})</span>
                    </span>
                `).join('');

                const shiftBadgesHtml = (v.assigned_shift_ids || []).map(sid => {
                    const sf = shiftsMap[sid];
                    return sf ? `<span class="badge badge-subtle mono text-[10px] mr-1">[${sf.code}]</span>` : '';
                }).join('') || '<span class="text-slate-400 italic">0 ca</span>';

                return `
                    <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                        <td class="py-3 px-3.5 text-center mono font-bold text-slate-400">${startIdx + i + 1}</td>
                        <td class="py-3 px-3.5">
                            <div class="flex items-center gap-2.5">
                                <img src="${v.avatar_url}" alt="" class="w-8 h-8 rounded-full object-cover ring-1 ring-[#0057B8]/30">
                                <div>
                                    <div class="font-bold text-slate-900 dark:text-white cursor-pointer hover:text-[#0057B8] btn-inspect-candidate" data-volunteer-id="${v.id}">${v.full_name}</div>
                                    <div class="text-[10px] text-slate-500 mono">${v.student_code} &middot; ${v.email}</div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-3.5 text-slate-600 dark:text-slate-300 font-semibold max-w-[160px] truncate">${v.chapter_name}</td>
                        <td class="py-3 px-3.5 max-w-[220px]">${skillsHtml}</td>
                        <td class="py-3 px-3.5">
                            <span class="badge badge-subtle text-[11px] mono font-bold">${v.max_shifts} ca/tuần</span>
                        </td>
                        <td class="py-3 px-3.5 max-w-[160px]">${shiftBadgesHtml}</td>
                        <td class="py-3 px-3.5">
                            <div class="font-extrabold text-emerald-600 dark:text-emerald-400 mono">${v.xp} XP</div>
                            <div class="text-[10px] text-slate-400">${v.unlocked_badges_count}/6 huy hiệu</div>
                        </td>
                        <td class="py-3 px-3.5 text-center">
                            <button type="button" class="btn btn-sm btn-outline btn-inspect-candidate py-1 px-2.5 text-xs text-[#0057B8] dark:text-sky-400 hover:bg-blue-50 dark:hover:bg-slate-800" data-volunteer-id="${v.id}">
                                Xem hồ sơ
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // DataGrid filters listeners
        const dgSearch = document.getElementById('datagrid-search-input');
        const dgUnit = document.getElementById('datagrid-unit-select');
        const dgSkill = document.getElementById('datagrid-skill-select');
        const dgShifts = document.getElementById('datagrid-shifts-select');
        const dgSize = document.getElementById('datagrid-page-size-select');

        if (dgSearch) dgSearch.addEventListener('input', () => { dataGridPage = 1; renderDataGrid(); });
        if (dgUnit) dgUnit.addEventListener('change', () => { dataGridPage = 1; renderDataGrid(); });
        if (dgSkill) dgSkill.addEventListener('change', () => { dataGridPage = 1; renderDataGrid(); });
        if (dgShifts) dgShifts.addEventListener('change', () => { dataGridPage = 1; renderDataGrid(); });
        if (dgSize) {
            dgSize.addEventListener('change', () => {
                dataGridPageSize = parseInt(dgSize.value, 10) || 10;
                dataGridPage = 1;
                renderDataGrid();
            });
        }

        // Initialize DataGrid
        renderDataGrid();
    }

    // =========================================================================
    // 16.3. CANDIDATE INSPECTOR MODAL AJAX LOADING & RENDERING (R1)
    // =========================================================================
    document.addEventListener('click', (e) => {
        const inspectBtn = e.target.closest('.btn-inspect-candidate');
        if (!inspectBtn) return;
        const vid = inspectBtn.dataset.volunteerId;
        if (!vid) return;

        XanhHubUI.openModal('modal-candidate-inspector');

        const loadingEl = document.getElementById('candidate-inspector-loading');
        const contentEl = document.getElementById('candidate-inspector-content');
        if (loadingEl) loadingEl.classList.remove('hidden');
        if (contentEl) contentEl.classList.add('hidden');

        const actId = (coordinatorData && coordinatorData.activity_id) ? coordinatorData.activity_id : '3';
        fetch(`?action=api_candidate_profile&volunteer_id=${encodeURIComponent(vid)}&activity_id=${encodeURIComponent(actId)}`)
            .then(r => r.json())
            .then(res => {
                if (!res.ok) {
                    XanhHubUI.toast('error', 'Không tìm thấy hồ sơ', res.message_vi || 'Lỗi tải hồ sơ tình nguyện viên');
                    XanhHubUI.closeModal('modal-candidate-inspector');
                    return;
                }

                const v = res.volunteer || {};
                const skills = res.skills || [];
                const avail = res.availability || [];
                const assigned = res.assigned_shifts || [];
                const g = res.gamification || {};

                // Header
                const avEl = document.getElementById('cand-avatar');
                if (avEl && v.avatar_url) avEl.src = v.avatar_url;
                const nameEl = document.getElementById('cand-name');
                if (nameEl) nameEl.textContent = v.full_name || '--';
                const codeEl = document.getElementById('cand-student-code');
                if (codeEl) codeEl.textContent = v.student_code || '--';
                const unitEl = document.getElementById('cand-unit');
                if (unitEl) unitEl.textContent = v.chapter_name || '--';
                const emailEl = document.getElementById('cand-email');
                if (emailEl) emailEl.textContent = v.email || '--';
                const phoneEl = document.getElementById('cand-phone');
                if (phoneEl) phoneEl.textContent = v.phone || '0901234567';
                const maxEl = document.getElementById('cand-max-shifts');
                if (maxEl) maxEl.textContent = `${v.max_shifts_per_week || 4} ca/tuần`;

                // Stats
                const hoursEl = document.getElementById('cand-merit-hours');
                if (hoursEl) hoursEl.textContent = `${g.total_hours || 0}h`;
                const shiftsEl = document.getElementById('cand-merit-shifts');
                if (shiftsEl) shiftsEl.textContent = `${g.completed_shifts || assigned.length || 0} ca`;
                const repEl = document.getElementById('cand-merit-reputation');
                if (repEl) repEl.textContent = (g.reputation_score || 4.5).toFixed(2);
                const xpEl = document.getElementById('cand-merit-xp');
                if (xpEl) xpEl.textContent = (g.xp || 0).toFixed(1);

                // Skills
                const skillsBox = document.getElementById('cand-skills-list');
                if (skillsBox) {
                    skillsBox.innerHTML = skills.length > 0 ? skills.map(sk => `
                        <span class="badge ${sk.is_verified ? 'badge-valid' : 'badge-info'} text-xs inline-flex items-center gap-1.5 py-1 px-2.5">
                            <span class="material-symbols-outlined text-[14px]">${sk.is_verified ? 'verified' : 'psychology'}</span>
                            <strong>${sk.name}</strong> &middot; Cấp ${sk.level}/5
                            ${sk.is_verified ? '<span class="text-[10px] text-emerald-800 font-bold">(Đã kiểm định)</span>' : '<span class="text-[10px] text-blue-800">(Tự khai)</span>'}
                        </span>
                    `).join('') : '<span class="text-slate-400">Chưa khai báo kỹ năng.</span>';
                }

                // 7-Day Availability Matrix
                const availBox = document.getElementById('cand-availability-grid');
                if (availBox) {
                    const days = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
                    const daySlots = {};
                    avail.forEach(a => {
                        const d = parseInt(a.day_of_week, 10);
                        if (!daySlots[d]) daySlots[d] = [];
                        daySlots[d].push(`${a.start_hm}–${a.end_hm}`);
                    });

                    availBox.innerHTML = days.map((dName, idx) => {
                        const dow = idx + 1;
                        const slots = daySlots[dow] || [];
                        const hasAvail = slots.length > 0;
                        return `
                            <div class="p-2 rounded-xl border ${hasAvail ? 'bg-emerald-50/60 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800' : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-800'}">
                                <span class="font-extrabold text-[11px] ${hasAvail ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400'} block mb-1">${dName}</span>
                                ${hasAvail
                                    ? slots.map(s => `<span class="badge badge-valid text-[9px] mono block mb-0.5 truncate">${s}</span>`).join('')
                                    : '<span class="text-[10px] text-slate-400">Bận</span>'
                                }
                            </div>
                        `;
                    }).join('');
                }

                // Assigned Shifts
                const shiftsBox = document.getElementById('cand-assigned-shifts-list');
                if (shiftsBox) {
                    shiftsBox.innerHTML = assigned.length > 0 ? assigned.map(as => `
                        <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex items-center justify-between text-xs">
                            <div>
                                <span class="mono font-bold text-[#0057B8] dark:text-sky-400">[${as.shift_code}]</span>
                                <strong class="ml-1 text-slate-900 dark:text-white">${as.title}</strong>
                                <div class="text-[11px] text-slate-500 mt-0.5">${as.start_iso}</div>
                            </div>
                            <span class="badge badge-valid mono text-[10px]">${as.status}</span>
                        </div>
                    `).join('') : '<div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl text-center text-slate-400 text-xs">Chưa tham gia ca trực nào trong chiến dịch này.</div>';
                }

                // Badges Grid
                const badgesBox = document.getElementById('cand-badges-grid');
                if (badgesBox) {
                    const badges = g.badges || [];
                    badgesBox.innerHTML = badges.length > 0 ? badges.map(b => `
                        <div class="p-2.5 rounded-xl border flex items-center gap-2 ${b.is_unlocked ? 'bg-amber-50/70 dark:bg-amber-950/30 border-amber-200 dark:border-amber-800' : 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-800 opacity-60'}">
                            <span class="material-symbols-outlined text-[20px] ${b.is_unlocked ? 'text-amber-500 filled' : 'text-slate-400'}">${b.icon || 'military_tech'}</span>
                            <div class="min-w-0">
                                <div class="font-bold text-xs ${b.is_unlocked ? 'text-slate-900 dark:text-white' : 'text-slate-500'} truncate">${b.name}</div>
                                <div class="text-[10px] text-slate-400 truncate">${b.description}</div>
                            </div>
                        </div>
                    `).join('') : '<span class="text-slate-400">Chưa mở khóa huy hiệu.</span>';
                }

                if (loadingEl) loadingEl.classList.add('hidden');
                if (contentEl) contentEl.classList.remove('hidden');
            })
            .catch(() => {
                XanhHubUI.toast('error', 'Lỗi kết nối', 'Không thể nạp hồ sơ ứng viên từ máy chủ.');
                XanhHubUI.closeModal('modal-candidate-inspector');
            });
    });

    // =========================================================================
    // 16.4. ĐÁNH GIÁ 360° MODAL (R1)
    // =========================================================================
    document.addEventListener('click', (e) => {
        const evalBtn = e.target.closest('.btn-open-eval-volunteer');
        if (!evalBtn) return;
        const assignId = evalBtn.dataset.assignmentId;
        const volId = evalBtn.dataset.volunteerId;
        const shiftId = evalBtn.dataset.shiftId;
        const volName = evalBtn.dataset.volunteerName || 'Tình nguyện viên';

        const form = document.getElementById('form-eval-volunteer');
        if (form) {
            const inpAssign = document.getElementById('eval-assignment-id');
            const inpVol = document.getElementById('eval-volunteer-id');
            const inpShift = document.getElementById('eval-shift-id');
            const nameEl = document.getElementById('eval-volunteer-name');

            if (inpAssign) inpAssign.value = assignId || '';
            if (inpVol) inpVol.value = volId || '';
            if (inpShift) inpShift.value = shiftId || '';
            if (nameEl) nameEl.textContent = volName;
        }

        XanhHubUI.openModal('modal-eval-volunteer');
    });

    const formEval = document.getElementById('form-eval-volunteer');
    if (formEval) {
        formEval.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(formEval);
            fetch('?action=api_submit_evaluation', {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        XanhHubUI.toast('error', 'Đánh giá không thành công', res.error || res.message_vi);
                        return;
                    }
                    XanhHubUI.closeModal('modal-eval-volunteer');
                    XanhHubUI.toast('success', 'Đã lưu đánh giá 360°', `Điểm trung bình: ${res.average_score || 5} sao`);
                    setTimeout(() => window.location.reload(), 650);
                })
                .catch(() => {
                    XanhHubUI.toast('error', 'Lỗi kết nối', 'Không thể kết nối máy chủ gửi đánh giá 360°.');
                });
        });
    }

    // =========================================================================
    // 16.5. WIZARD 2 BƯỚC: TẠO HOẠT ĐỘNG MỚI (R4)
    // =========================================================================
    const btnWizNext = document.getElementById('btn-wizard-next');
    const btnWizPrev = document.getElementById('btn-wizard-prev');
    const step1 = document.getElementById('wizard-step-1');
    const step2 = document.getElementById('wizard-step-2');
    const ind1 = document.getElementById('step-indicator-1');
    const ind2 = document.getElementById('step-indicator-2');

    if (btnWizNext && btnWizPrev && step1 && step2) {
        btnWizNext.addEventListener('click', () => {
            const actCode = (document.getElementById('input-act-code')?.value || '').trim();
            const actTitle = (document.getElementById('input-act-title')?.value || '').trim();
            const actLoc = (document.getElementById('input-act-location')?.value || '').trim();

            if (!actCode || !actTitle || !actLoc) {
                XanhHubUI.toast('warning', 'Thiếu thông tin bắt buộc', 'Vui lòng điền đầy đủ Mã, Tên và Địa bàn trọng điểm của chiến dịch trước khi tiếp tục.');
                return;
            }

            // Auto-populate step 2 defaults if empty
            const shiftCodeInp = document.getElementById('input-shift-code');
            const shiftTitleInp = document.getElementById('input-shift-title');
            const shiftLocInp = document.getElementById('input-shift-location');

            if (shiftCodeInp && !shiftCodeInp.value) {
                shiftCodeInp.value = actCode.toUpperCase() + '-CA01';
            }
            if (shiftTitleInp && !shiftTitleInp.value) {
                shiftTitleInp.value = 'Ca xuất quân ' + actTitle;
            }
            if (shiftLocInp && !shiftLocInp.value) {
                shiftLocInp.value = actLoc;
            }

            step1.classList.add('hidden');
            step2.classList.remove('hidden');

            if (ind1 && ind2) {
                ind1.classList.remove('text-[#0057B8]', 'dark:text-sky-400');
                ind1.classList.add('text-emerald-600', 'dark:text-emerald-400');
                ind2.classList.remove('text-slate-400');
                ind2.classList.add('text-[#0057B8]', 'dark:text-sky-400');
            }
        });

        btnWizPrev.addEventListener('click', () => {
            step2.classList.add('hidden');
            step1.classList.remove('hidden');

            if (ind1 && ind2) {
                ind2.classList.remove('text-[#0057B8]', 'dark:text-sky-400');
                ind2.classList.add('text-slate-400');
                ind1.classList.remove('text-emerald-600', 'dark:text-emerald-400');
                ind1.classList.add('text-[#0057B8]', 'dark:text-sky-400');
            }
        });
    }

    // =========================================================================
    // ADMIN TAB SWITCHER (R2 Requirement)
    // =========================================================================
    const adminTabBtns = document.querySelectorAll('.admin-tab-btn');
    const adminTabPanels = document.querySelectorAll('.admin-tab-panel');
    if (adminTabBtns.length > 0 && adminTabPanels.length > 0) {
        function activateAdminTab(targetTab) {
            adminTabBtns.forEach(btn => {
                const tab = btn.dataset.adminTab;
                if (tab === targetTab) {
                    btn.classList.add('border-purple-600', 'text-purple-600', 'dark:text-purple-400');
                    btn.classList.remove('border-transparent', 'text-slate-500', 'dark:text-slate-400');
                } else {
                    btn.classList.remove('border-purple-600', 'text-purple-600', 'dark:text-purple-400');
                    btn.classList.add('border-transparent', 'text-slate-500', 'dark:text-slate-400');
                }
            });
            adminTabPanels.forEach(panel => {
                if (panel.id === `admin-tab-${targetTab}`) {
                    panel.classList.remove('hidden');
                } else {
                    panel.classList.add('hidden');
                }
            });
        }

        adminTabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.adminTab;
                if (tab) {
                    activateAdminTab(tab);
                    try {
                        history.replaceState(null, '', `#admin-tab-${tab}`);
                    } catch (e) {}
                }
            });
        });

        const initialHash = window.location.hash;
        if (initialHash && initialHash.startsWith('#admin-tab-')) {
            const initialTab = initialHash.replace('#admin-tab-', '');
            if (['users', 'verification', 'settings', 'audit', 'news'].includes(initialTab)) {
                activateAdminTab(initialTab);
            }
        }

        // Đổ dữ liệu modal chỉnh sửa bản tin
        document.querySelectorAll('.btn-edit-news').forEach(btn => {
            btn.addEventListener('click', () => {
                const setVal = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.value = val !== undefined && val !== null ? val : '';
                };
                setVal('edit-news-id', btn.dataset.id);
                setVal('edit-news-title', btn.dataset.title);
                setVal('edit-news-tag', btn.dataset.tag);
                setVal('edit-news-summary', btn.dataset.summary);
                setVal('edit-news-content', btn.dataset.content);
                setVal('edit-news-image-url', btn.dataset.image);
                setVal('edit-news-activity-id', btn.dataset.activityId);
                setVal('edit-news-status', btn.dataset.status);
                setVal('edit-news-date', btn.dataset.date);

                if (window.XanhHubUI && typeof window.XanhHubUI.openModal === 'function') {
                    window.XanhHubUI.openModal('modal-admin-edit-news');
                }
            });
        });
    }

    // =========================================================================
    // 17. BỘ CHUYỂN ĐỔI CHIẾN DỊCH QUY MÔ LỚN (100+ CHIẾN DỊCH) & LỊCH BIỂU TRỰC QUAN
    // =========================================================================
    document.querySelectorAll('.campaign-scalable-switcher').forEach(switcher => {
        const input = switcher.querySelector('.campaign-quick-filter-input');
        const items = switcher.querySelectorAll('.campaign-quick-filter-item');
        if (input && items.length > 0) {
            input.addEventListener('input', () => {
                const q = input.value.toLowerCase().trim();
                items.forEach(item => {
                    const hay = (item.dataset.campaignSearch || item.textContent || '').toLowerCase();
                    item.style.display = (!q || hay.includes(q)) ? '' : 'none';
                });
            });
        }
    });

    document.addEventListener('click', (e) => {
        document.querySelectorAll('.campaign-scalable-switcher details[open]').forEach(det => {
            if (!det.contains(e.target)) {
                det.removeAttribute('open');
            }
        });
    });

    // Chuyển đổi chế độ xem Lịch trực của Tình nguyện viên (Lịch theo Ngày & Buổi vs Dạng Thẻ)
    const myShiftsViewBtns = document.querySelectorAll('.btn-my-shifts-view');
    const myShiftsScheduleView = document.getElementById('my-shifts-schedule-view');
    const myShiftsCardsView = document.getElementById('my-shifts-cards-view');
    if (myShiftsViewBtns.length > 0 && myShiftsScheduleView && myShiftsCardsView) {
        myShiftsViewBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.myShiftsView || 'schedule';
                myShiftsViewBtns.forEach(b => {
                    const isAct = (b.dataset.myShiftsView === mode);
                    b.classList.toggle('active', isAct);
                    b.classList.toggle('bg-white', isAct);
                    b.classList.toggle('dark:bg-slate-900', isAct);
                    b.classList.toggle('text-[#0057B8]', isAct);
                    b.classList.toggle('dark:text-sky-400', isAct);
                    b.classList.toggle('shadow-xs', isAct);
                    b.classList.toggle('text-slate-600', !isAct);
                    b.classList.toggle('dark:text-slate-300', !isAct);
                });
                if (mode === 'cards') {
                    myShiftsScheduleView.classList.add('hidden');
                    myShiftsCardsView.classList.remove('hidden');
                } else {
                    myShiftsCardsView.classList.add('hidden');
                    myShiftsScheduleView.classList.remove('hidden');
                }
            });
        });
    }

    // Chuyển đổi chế độ xem & Bộ lọc thông minh cho Tab Đăng ký Ca trực của Tình nguyện viên
    const regViewBtns = document.querySelectorAll('.btn-reg-view');
    const regScheduleView = document.getElementById('vol-reg-schedule-view');
    const regTableView = document.getElementById('vol-reg-table-view');
    if (regViewBtns.length > 0 && regScheduleView && regTableView) {
        regViewBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.regView || 'schedule';
                regViewBtns.forEach(b => {
                    const isAct = (b.dataset.regView === mode);
                    b.classList.toggle('active', isAct);
                    b.classList.toggle('bg-white', isAct);
                    b.classList.toggle('dark:bg-slate-900', isAct);
                    b.classList.toggle('text-[#0057B8]', isAct);
                    b.classList.toggle('dark:text-sky-400', isAct);
                    b.classList.toggle('shadow-xs', isAct);
                    b.classList.toggle('text-slate-600', !isAct);
                    b.classList.toggle('dark:text-slate-300', !isAct);
                });
                if (mode === 'table') {
                    regScheduleView.classList.add('hidden');
                    regTableView.classList.remove('hidden');
                } else {
                    regTableView.classList.add('hidden');
                    regScheduleView.classList.remove('hidden');
                }
            });
        });
    }

    const regSearchInput = document.getElementById('vol-reg-search');
    const regSessionBtns = document.querySelectorAll('.btn-reg-session');
    const regCompatSelect = document.getElementById('vol-reg-compat');
    let activeRegSession = 'ALL';

    function applyVolunteerRegFilters() {
        const q = (regSearchInput?.value || '').toLowerCase().trim();
        const compat = regCompatSelect?.value || 'ALL';
        const items = document.querySelectorAll('.vol-reg-item');
        if (items.length === 0) return;

        items.forEach(el => {
            const hay = (el.dataset.regSearch || '').toLowerCase();
            const sess = el.dataset.regSession || '';
            const cmp = el.dataset.regCompat || '';
            const reg = el.dataset.regRegistered || '';

            const matchQ = !q || hay.includes(q);
            const matchSess = (activeRegSession === 'ALL' || sess === activeRegSession);
            const matchCompat = (compat === 'ALL')
                || (compat === 'MATCH' && cmp === 'MATCH')
                || (compat === 'REGISTERED' && reg === 'REGISTERED');

            el.style.display = (matchQ && matchSess && matchCompat) ? '' : 'none';
        });

        document.querySelectorAll('.vol-reg-day-group').forEach(group => {
            const visibleChildren = Array.from(group.querySelectorAll('.vol-reg-item')).filter(c => c.style.display !== 'none');
            group.style.display = visibleChildren.length > 0 ? '' : 'none';
        });
    }

    if (regSearchInput) {
        regSearchInput.addEventListener('input', applyVolunteerRegFilters);
    }
    if (regCompatSelect) {
        regCompatSelect.addEventListener('change', applyVolunteerRegFilters);
    }
    if (regSessionBtns.length > 0) {
        regSessionBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                activeRegSession = btn.dataset.regSession || 'ALL';
                regSessionBtns.forEach(b => {
                    const isAct = (b.dataset.regSession === activeRegSession);
                    b.classList.toggle('active', isAct);
                    b.classList.toggle('bg-[#0057B8]', isAct);
                    b.classList.toggle('text-white', isAct);
                    b.classList.toggle('bg-white', !isAct);
                    b.classList.toggle('dark:bg-slate-900', !isAct);
                    b.classList.toggle('text-slate-600', !isAct);
                    b.classList.toggle('dark:text-slate-300', !isAct);
                });
                applyVolunteerRegFilters();
            });
        });
    }

    // Bộ lọc tìm kiếm nhanh không dấu cho các bảng dữ liệu trong Cổng Quản trị (?view=admin)
    document.querySelectorAll('[data-admin-table-search]').forEach(inp => {
        inp.addEventListener('input', () => {
            const tbodyId = inp.dataset.adminTableSearch;
            const tbody = tbodyId ? document.getElementById(tbodyId) : null;
            if (!tbody) return;
            const q = normalizeVi(inp.value || '');
            tbody.querySelectorAll('tr').forEach(tr => {
                const text = normalizeVi(tr.textContent || '');
                tr.style.display = (!q || text.includes(q)) ? '' : 'none';
            });
        });
    });

    // Kích hoạt Modal Sửa Hoạt động / Chiến dịch trong Admin Tab Activities
    document.querySelectorAll('.btn-admin-edit-act-trigger').forEach(btn => {
        btn.addEventListener('click', () => {
            const actId = btn.dataset.actId || '';
            const actTitle = btn.dataset.actTitle || '';
            const actCode = btn.dataset.actCode || '';
            const actStatus = btn.dataset.actStatus || 'OPEN';
            const actDesc = btn.dataset.actDesc || '';
            const actLoc = btn.dataset.actLocation || '';
            const actLat = btn.dataset.actLat || '21.0285';
            const actLng = btn.dataset.actLng || '105.8542';

            const inputId = document.getElementById('edit-act-id');
            const inputTitle = document.getElementById('edit-act-title');
            const inputCode = document.getElementById('edit-act-code');
            const selectStatus = document.getElementById('edit-act-status');
            const inputDesc = document.getElementById('edit-act-desc');
            const inputLoc = document.getElementById('edit-act-location');
            const inputLat = document.getElementById('edit-act-lat');
            const inputLng = document.getElementById('edit-act-lng');

            if (inputId) inputId.value = actId;
            if (inputTitle) inputTitle.value = actTitle;
            if (inputCode) inputCode.value = actCode;
            if (selectStatus) selectStatus.value = actStatus;
            if (inputDesc) inputDesc.value = actDesc;
            if (inputLoc) inputLoc.value = actLoc;
            if (inputLat) inputLat.value = actLat;
            if (inputLng) inputLng.value = actLng;

            if (window.XanhHubUI && typeof window.XanhHubUI.openModal === 'function') {
                window.XanhHubUI.openModal('modal-admin-edit-activity');
            }
        });
    });

    // =========================================================================
    // HỆ THỐNG GỢI Ý ĐỊA CHỈ KIỂU GOOGLE MAPS & REVERSE GEOCODING GPS
    // =========================================================================
    function initPlacesAutocomplete() {
        const inputs = document.querySelectorAll('input[data-places-autocomplete="true"]');
        if (inputs.length === 0) return;

        let portal = document.getElementById('xanhhub-places-portal');
        if (!portal) {
            portal = document.createElement('div');
            portal.id = 'xanhhub-places-portal';
            portal.className = 'hidden fixed z-[9999] bg-white dark:bg-[#131822] border border-slate-200 dark:border-slate-700 rounded-2xl shadow-2xl overflow-hidden max-h-80 overflow-y-auto text-left';
            portal.style.minWidth = '280px';
            document.body.appendChild(portal);
        }

        let activeInput = null;
        let fetchSeq = 0;
        let debounceTimer = null;

        const positionPortal = (inp) => {
            if (!inp || portal.classList.contains('hidden')) return;
            const rect = inp.getBoundingClientRect();
            const vw = window.innerWidth || 1024;
            const width = Math.min(Math.max(rect.width, 300), vw - 24);
            const left = Math.max(12, Math.min(rect.left, vw - width - 12));
            portal.style.width = `${width}px`;
            portal.style.left = `${left}px`;
            portal.style.top = `${rect.bottom + 6}px`;
        };

        const applySelectedPlace = (inp, place) => {
            if (!inp || !place) return;
            const label = place.full_address || place.main_text || '';
            inp.value = label;
            inp.dispatchEvent(new Event('input', { bubbles: true }));

            const latSel = inp.dataset.latInput;
            const lngSel = inp.dataset.lngInput;
            if (latSel && typeof place.lat === 'number') {
                const latEl = document.querySelector(latSel);
                if (latEl) {
                    latEl.value = place.lat.toFixed(5);
                    latEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            if (lngSel && typeof place.lng === 'number') {
                const lngEl = document.querySelector(lngSel);
                if (lngEl) {
                    lngEl.value = place.lng.toFixed(5);
                    lngEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            inp.dispatchEvent(new CustomEvent('place-autocomplete-selected', { detail: place, bubbles: true }));
            portal.classList.add('hidden');
        };

        const renderSuggestions = (inp, places, isLoading = false) => {
            activeInput = inp;
            const rowsHtml = (places || []).map((pl, idx) => `
                <button type="button" data-place-idx="${idx}" class="w-full px-3.5 py-2.5 flex items-start gap-2.5 hover:bg-blue-50 dark:hover:bg-slate-800/90 transition-colors border-t border-slate-100 dark:border-slate-800 text-left">
                    <span class="material-symbols-outlined text-rose-500 text-[18px] mt-0.5 shrink-0">location_on</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-xs text-slate-900 dark:text-white truncate">${escapeHtml(pl.main_text || '')}</span>
                            ${pl.district ? `<span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 shrink-0">${escapeHtml(pl.district)}</span>` : ''}
                        </div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 truncate">${escapeHtml(pl.full_address || pl.secondary_text || '')}</div>
                        <div class="text-[10px] text-emerald-600 dark:text-emerald-400 mono mt-0.5">${Number(pl.lat).toFixed(4)}° N, ${Number(pl.lng).toFixed(4)}° E</div>
                    </div>
                </button>
            `).join('');

            portal.innerHTML = `
                <button type="button" id="xanhhub-places-use-gps" class="w-full px-3.5 py-2.5 flex items-center gap-2.5 bg-emerald-50/70 dark:bg-emerald-950/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 text-emerald-800 dark:text-emerald-300 text-xs font-bold transition-colors text-left">
                    <span class="material-symbols-outlined text-[18px] text-emerald-600 shrink-0">my_location</span>
                    <span>Dùng vị trí GPS hiện tại của tôi (Tự điền địa chỉ)</span>
                </button>
                ${isLoading && (!places || places.length === 0)
                    ? '<div class="px-4 py-3 text-xs text-slate-400">Đang tìm địa chỉ tại TP. Hà Nội...</div>'
                    : (rowsHtml || '<div class="px-4 py-3 text-xs text-slate-400">Không tìm thấy địa danh gợi ý — bạn có thể giữ nguyên địa chỉ đã gõ hoặc dùng định vị GPS.</div>')}
            `;

            portal.classList.remove('hidden');
            positionPortal(inp);

            const gpsBtn = document.getElementById('xanhhub-places-use-gps');
            if (gpsBtn) {
                gpsBtn.addEventListener('mousedown', (e) => e.preventDefault());
                gpsBtn.addEventListener('click', () => {
                    if (!navigator.geolocation) {
                        XanhHubUI.toast('warning', 'Trình duyệt chưa bật GPS', 'Vui lòng chọn một địa chỉ gợi ý bên dưới.');
                        return;
                    }
                    gpsBtn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">refresh</span><span>Đang lấy tọa độ GPS & giải mã địa chỉ...</span>';
                    navigator.geolocation.getCurrentPosition(
                        (pos) => {
                            const lat = pos.coords.latitude;
                            const lng = pos.coords.longitude;
                            fetch(`?action=api_reverse_geocode&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`)
                                .then(r => r.json())
                                .then(geo => {
                                    const fullAddr = (geo && geo.ok && (geo.full_address || (geo.address && geo.address.full_address)))
                                        ? (geo.full_address || geo.address.full_address)
                                        : `Tọa độ (${lat.toFixed(4)}, ${lng.toFixed(4)}) TP. Hà Nội`;
                                    applySelectedPlace(inp, {
                                        main_text: (geo && (geo.place_name || (geo.address && geo.address.street_or_landmark))) || fullAddr,
                                        full_address: fullAddr,
                                        district: (geo && (geo.district || (geo.address && geo.address.district))) || '',
                                        lat,
                                        lng
                                    });
                                    XanhHubUI.toast('success', 'Đã xác định địa chỉ GPS', fullAddr);
                                })
                                .catch(() => {
                                    applySelectedPlace(inp, {
                                        main_text: 'Vị trí GPS thiết bị',
                                        full_address: `TP. Hà Nội (${lat.toFixed(4)}, ${lng.toFixed(4)})`,
                                        lat,
                                        lng
                                    });
                                });
                        },
                        () => {
                            XanhHubUI.toast('warning', 'Không thể đọc GPS thiết bị', 'Vui lòng cấp quyền vị trí hoặc chọn địa chỉ gợi ý từ danh sách.');
                            portal.classList.add('hidden');
                        },
                        { enableHighAccuracy: true, timeout: 7000 }
                    );
                });
            }

            portal.querySelectorAll('[data-place-idx]').forEach(btn => {
                btn.addEventListener('mousedown', (e) => e.preventDefault());
                btn.addEventListener('click', () => {
                    const idx = parseInt(btn.dataset.placeIdx || '-1', 10);
                    if (places && places[idx]) {
                        applySelectedPlace(inp, places[idx]);
                    }
                });
            });
        };

        const loadPlaces = (inp) => {
            const seq = ++fetchSeq;
            const q = inp.value.trim();
            fetch(`?action=api_places_autocomplete&q=${encodeURIComponent(q)}&limit=8`)
                .then(r => r.json())
                .then(res => {
                    if (seq !== fetchSeq) return;
                    if (res && res.ok) {
                        renderSuggestions(inp, res.places || [], false);
                    }
                })
                .catch(() => {});
        };

        inputs.forEach(inp => {
            inp.addEventListener('focus', () => {
                renderSuggestions(inp, [], true);
                loadPlaces(inp);
            });
            inp.addEventListener('input', () => {
                if (debounceTimer) clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => loadPlaces(inp), 130);
            });
        });

        window.addEventListener('scroll', () => {
            if (activeInput && !portal.classList.contains('hidden')) positionPortal(activeInput);
        }, true);
        window.addEventListener('resize', () => {
            if (activeInput && !portal.classList.contains('hidden')) positionPortal(activeInput);
        });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('input[data-places-autocomplete="true"]') && !e.target.closest('#xanhhub-places-portal')) {
                portal.classList.add('hidden');
            }
        });
    }

    initPlacesAutocomplete();

    // Tự động lấy vị trí GPS khi mở trang Đăng ký tình nguyện viên (?view=register)
    const regAddrInput = document.getElementById('register-address-input');
    if (regAddrInput && regAddrInput.value.trim() === '' && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                if (document.activeElement === regAddrInput || regAddrInput.value.trim() !== '') return;
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                const regLatEl = document.getElementById('register-lat-input');
                const regLngEl = document.getElementById('register-lng-input');
                if (regLatEl) regLatEl.value = lat.toFixed(5);
                if (regLngEl) regLngEl.value = lng.toFixed(5);
                fetch(`?action=api_reverse_geocode&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`)
                    .then(r => r.json())
                    .then(geo => {
                        if (regAddrInput.value.trim() === '' && geo && geo.ok) {
                            regAddrInput.value = geo.full_address || `TP. Hà Nội (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
                        }
                    })
                    .catch(() => {});
            },
            () => {},
            { enableHighAccuracy: true, timeout: 6000, maximumAge: 60000 }
        );
    }

    // Kích hoạt Hệ thống Giám sát & Đánh giá Độc lập trong Admin Tab Sentinel
    const triggerSentinelProbe = () => {
        const btn = document.getElementById('btn-run-sentinel-probe');
        const badge = document.getElementById('sentinel-score-badge');
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">refresh</span> <span>Đang kiểm định toàn diện...</span>';
        }
        if (badge) {
            badge.textContent = 'Đang rà soát...';
            badge.className = 'px-3 py-1 rounded-full text-xs font-black mono bg-amber-500 text-white animate-pulse';
        }

        fetch('?action=admin_run_sentinel', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.summary) {
                if (window.XanhHubUI && typeof window.XanhHubUI.toast === 'function') {
                    window.XanhHubUI.toast('success', 'Giám định Độc lập Thành công', `Đã kiểm tra ${data.summary.total_probes} tiêu chí · Điểm đạt: ${data.summary.health_score}%`);
                }
                setTimeout(() => {
                    window.location.hash = '#admin-tab-sentinel';
                    window.location.reload();
                }, 600);
            } else {
                if (window.XanhHubUI && typeof window.XanhHubUI.toast === 'function') {
                    window.XanhHubUI.toast('error', 'Lỗi Kiểm Định', data.message || 'Không thể chạy kiểm định độc lập.');
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            }
        })
        .catch(err => {
            if (window.XanhHubUI && typeof window.XanhHubUI.toast === 'function') {
                window.XanhHubUI.toast('error', 'Lỗi Kết Nối', 'Không thể kết nối đến Sentinel Service.');
            }
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });
    };

    const sentinelBtn = document.getElementById('btn-run-sentinel-probe');
    if (sentinelBtn) sentinelBtn.addEventListener('click', triggerSentinelProbe);
    const sentinelRecheckBtn = document.getElementById('btn-recheck-sentinel');
    if (sentinelRecheckBtn) sentinelRecheckBtn.addEventListener('click', triggerSentinelProbe);

    // =========================================================================
    // 14. ĐA NGÔN NGỮ TOÀN TRANG BẰNG BACKEND TRANSLATION API + CACHE BỀN VỮNG
    // =========================================================================
    const i18nEnDict = {
        'brand.subtitle': 'Youth Volunteer Hub Vietnam',
        'search.placeholder': 'Search campaigns, shifts, skills (First Aid, Logistics)...',
        'search.heading': 'Suggested Campaigns & Shifts',
        'header.scan_qr': 'Scan QR',
        'header.utilities': 'Utilities',
        'header.analytics': 'BI Analytics',
        'auth.login': 'Sign In',
        'auth.register': 'Register',
        'nav.home': 'Home',
        'nav.campaigns': 'Campaigns',
        'nav.campaigns_all': 'All Shifts & Campaigns Filter',
        'nav.skills': 'Skill Groups',
        'nav.map': 'GIS Map',
        'nav.news': 'News & Film',
        'nav.about': 'About Us',
        'nav.coordinator': 'Coordinator Desk',
        'nav.profile_coord': 'Commander Profile',
        'nav.admin': 'System Admin',
        'nav.volunteer_portal': 'Volunteer Portal',
        'nav.profile': 'My Profile',
        'nav.volunteer': 'Volunteers',
        'nav.verify': 'Certificates',
        'nav.leaderboard': 'Leaderboard',
        'util.title': 'Quick Utilities Center',
        'util.gps_label': 'Live GPS Address',
        'util.gps_btn': 'Locate Now',
        'util.gps_hint': 'Click "Locate Now" to resolve your real street address.',
        'util.font_label': 'Display Font Size',
        'util.verify_label': 'Quick Certificate Verify',
        'util.verify_go': 'Verify',
        'util.cinema': '4K Cinema',
        'util.ics': 'Export .ICS'
    };

    const phraseViToEn = {
        'Chiến dịch Mùa Hè Xanh 2026': 'Green Summer Campaign 2026',
        'Chiến dịch Tiếp Sức Mùa Thi': 'Exam Support Campaign',
        'Chiến dịch Chủ Nhật Xanh': 'Green Sunday Campaign',
        'Khám phá Các Ca trực đang Tuyển quân': 'Explore Open Volunteer Shifts',
        'Tất cả': 'All',
        'Thước phim Hành trình & Bản tin Áo Xanh': 'Field Documentary & Youth News',
        'Bản đồ Số GIS & Định vị Điểm Tình nguyện': 'Interactive GIS Map & Shift Locator',
        'Bảng Vàng Thi Đua & Vinh Danh Tình Nguyện Viên': 'Volunteer Honor Leaderboard',
        'Tra cứu Giấy Chứng nhận & Văn bản Điện tử': 'Electronic Certificate Verification',
        'Đăng nhập Hệ thống Điều phối': 'Coordinator Portal Sign In',
        'Đăng ký Tình nguyện viên': 'Volunteer Registration'
    };

    const I18N_CACHE_STORAGE_KEY = 'xanhhub_i18n_cache_en_v2';
    const loadI18nClientCache = () => {
        const base = Object.assign({}, phraseViToEn);
        try {
            const raw = localStorage.getItem(I18N_CACHE_STORAGE_KEY);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    Object.assign(base, parsed);
                }
            }
        } catch (err) {}
        return base;
    };
    let i18nClientCache = loadI18nClientCache();
    const persistI18nClientCache = () => {
        try {
            const keys = Object.keys(i18nClientCache);
            if (keys.length > 2500) {
                const trimmed = {};
                keys.slice(-2000).forEach(k => { trimmed[k] = i18nClientCache[k]; });
                i18nClientCache = trimmed;
            }
            localStorage.setItem(I18N_CACHE_STORAGE_KEY, JSON.stringify(i18nClientCache));
        } catch (err) {}
    };

    const origTextNodeMeta = new WeakMap();
    const touchedTextNodes = new Set();
    const touchedAttrElements = new Set();
    const SKIP_I18N_TAGS = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'CODE', 'PRE', 'SVG', 'KBD', 'SAMP']);
    let currentUiLang = 'vi';
    let langSessionToken = 0;
    let isMutatingI18nDom = false;
    let i18nObserverTimer = null;

    const isTranslatableText = (rawText) => {
        const trimmed = (rawText || '').trim();
        if (trimmed.length < 2) return false;
        if (!/\p{L}/u.test(trimmed)) return false;
        if (/^(https?:\/\/|mailto:|tel:|sha256:|[0-9a-f]{16,}$)/i.test(trimmed)) return false;
        if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/i.test(trimmed)) return false;
        if (/^[A-Z0-9_.\-/:]{2,16}$/.test(trimmed)) return false;
        return true;
    };

    const splitWhitespace = (raw) => {
        const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(raw || '');
        if (!m) return { lead: '', core: raw || '', trail: '' };
        return { lead: m[1], core: m[2], trail: m[3] };
    };

    const shouldSkipI18nElement = (el) => {
        if (!el || el.nodeType !== 1) return true;
        if (SKIP_I18N_TAGS.has(el.tagName)) return true;
        if (el.closest('.material-symbols-outlined, .material-icons, .notranslate, [data-no-i18n]')) return true;
        return false;
    };

    const applyKeyedElements = (targetLang) => {
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.dataset.i18n;
            if (el.dataset.origVi === undefined) {
                el.dataset.origVi = el.textContent;
            }
            if (targetLang === 'en' && i18nEnDict[key]) {
                el.textContent = i18nEnDict[key];
            } else if (el.dataset.origVi !== undefined) {
                el.textContent = el.dataset.origVi;
            }
        });

        document.querySelectorAll('[data-i18n-placeholder]').forEach(inp => {
            const key = inp.dataset.i18nPlaceholder;
            if (inp.dataset.origViPh === undefined) {
                inp.dataset.origViPh = inp.getAttribute('placeholder') || '';
            }
            if (targetLang === 'en' && i18nEnDict[key]) {
                inp.setAttribute('placeholder', i18nEnDict[key]);
            } else if (inp.dataset.origViPh !== undefined) {
                inp.setAttribute('placeholder', inp.dataset.origViPh);
            }
        });
    };

    const collectAndApplyFullPageTranslation = async (token) => {
        if (currentUiLang !== 'en' || token !== langSessionToken) return;

        const textNodeMap = new Map();
        const attrTargetMap = new Map();
        const missingStrings = new Set();

        const queueTarget = (orig, setterFn) => {
            if (Object.prototype.hasOwnProperty.call(i18nClientCache, orig) && i18nClientCache[orig]) {
                setterFn(i18nClientCache[orig]);
            } else {
                missingStrings.add(orig);
            }
        };

        isMutatingI18nDom = true;
        try {
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
                acceptNode(node) {
                    const parent = node.parentElement;
                    if (!parent || shouldSkipI18nElement(parent)) return NodeFilter.FILTER_REJECT;
                    if (parent.hasAttribute('data-i18n') && i18nEnDict[parent.dataset.i18n]) {
                        return NodeFilter.FILTER_REJECT;
                    }
                    const meta = origTextNodeMeta.get(node);
                    const checkText = meta ? meta.orig : node.nodeValue;
                    return isTranslatableText(checkText) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
                }
            });

            let cur;
            while ((cur = walker.nextNode())) {
                let meta = origTextNodeMeta.get(cur);
                if (!meta) {
                    const parts = splitWhitespace(cur.nodeValue);
                    meta = { orig: parts.core, lead: parts.lead, trail: parts.trail };
                    origTextNodeMeta.set(cur, meta);
                    touchedTextNodes.add(cur);
                }
                const orig = meta.orig;
                if (!textNodeMap.has(orig)) textNodeMap.set(orig, []);
                textNodeMap.get(orig).push(cur);

                if (Object.prototype.hasOwnProperty.call(i18nClientCache, orig) && i18nClientCache[orig]) {
                    const nextVal = meta.lead + i18nClientCache[orig] + meta.trail;
                    if (cur.nodeValue !== nextVal) cur.nodeValue = nextVal;
                } else {
                    missingStrings.add(orig);
                }
            }

            document.querySelectorAll('input[placeholder], textarea[placeholder], [title]').forEach(el => {
                if (shouldSkipI18nElement(el)) return;
                if (el.hasAttribute('placeholder') && !(el.hasAttribute('data-i18n-placeholder') && i18nEnDict[el.dataset.i18nPlaceholder])) {
                    if (el.dataset.origViFullPh === undefined) {
                        el.dataset.origViFullPh = el.getAttribute('placeholder') || '';
                        touchedAttrElements.add(el);
                    }
                    const origPh = el.dataset.origViFullPh.trim();
                    if (isTranslatableText(origPh)) {
                        if (!attrTargetMap.has(origPh)) attrTargetMap.set(origPh, []);
                        attrTargetMap.get(origPh).push({ el, attr: 'placeholder' });
                        queueTarget(origPh, (tr) => {
                            if (el.getAttribute('placeholder') !== tr) el.setAttribute('placeholder', tr);
                        });
                    }
                }
                if (el.hasAttribute('title')) {
                    if (el.dataset.origViTitle === undefined) {
                        el.dataset.origViTitle = el.getAttribute('title') || '';
                        touchedAttrElements.add(el);
                    }
                    const origTitle = el.dataset.origViTitle.trim();
                    if (isTranslatableText(origTitle)) {
                        if (!attrTargetMap.has(origTitle)) attrTargetMap.set(origTitle, []);
                        attrTargetMap.get(origTitle).push({ el, attr: 'title' });
                        queueTarget(origTitle, (tr) => {
                            if (el.getAttribute('title') !== tr) el.setAttribute('title', tr);
                        });
                    }
                }
            });
        } finally {
            isMutatingI18nDom = false;
        }

        const missingList = Array.from(missingStrings);
        if (missingList.length === 0) return;

        const CHUNK_SIZE = 45;
        const chunks = [];
        for (let i = 0; i < missingList.length; i += CHUNK_SIZE) {
            chunks.push(missingList.slice(i, i + CHUNK_SIZE));
        }

        const applyTranslatedChunk = (mapObj) => {
            if (!mapObj || currentUiLang !== 'en' || token !== langSessionToken) return;
            isMutatingI18nDom = true;
            try {
                let cacheUpdated = false;
                Object.keys(mapObj).forEach(orig => {
                    const tr = mapObj[orig];
                    if (typeof tr !== 'string' || !tr.trim()) return;
                    if (tr.trim() !== orig.trim() || !/[^\x00-\x7F]/.test(orig)) {
                        i18nClientCache[orig] = tr;
                        cacheUpdated = true;
                    }

                    const nodes = textNodeMap.get(orig);
                    if (nodes) {
                        nodes.forEach(node => {
                            if (!node.isConnected) return;
                            const meta = origTextNodeMeta.get(node);
                            if (!meta) return;
                            const nextVal = meta.lead + tr + meta.trail;
                            if (node.nodeValue !== nextVal) node.nodeValue = nextVal;
                        });
                    }
                    const attrs = attrTargetMap.get(orig);
                    if (attrs) {
                        attrs.forEach(item => {
                            if (item.el.isConnected && item.el.getAttribute(item.attr) !== tr) {
                                item.el.setAttribute(item.attr, tr);
                            }
                        });
                    }
                });
                if (cacheUpdated) persistI18nClientCache();
            } finally {
                isMutatingI18nDom = false;
            }
        };

        await Promise.all(chunks.map(async (chunk) => {
            if (currentUiLang !== 'en' || token !== langSessionToken) return;
            try {
                const res = await fetch('?action=api_translate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ target: 'en', source: 'vi', texts: chunk })
                });
                if (!res.ok) return;
                const data = await res.json();
                if (data && data.ok && data.translations) {
                    applyTranslatedChunk(data.translations);
                }
            } catch (err) {}
        }));
    };

    const restoreAllVietnameseText = () => {
        isMutatingI18nDom = true;
        try {
            touchedTextNodes.forEach(node => {
                if (!node.isConnected) {
                    touchedTextNodes.delete(node);
                    return;
                }
                const meta = origTextNodeMeta.get(node);
                if (meta) {
                    const origFull = meta.lead + meta.orig + meta.trail;
                    if (node.nodeValue !== origFull) node.nodeValue = origFull;
                }
            });
            touchedAttrElements.forEach(el => {
                if (!el.isConnected) {
                    touchedAttrElements.delete(el);
                    return;
                }
                if (el.dataset.origViFullPh !== undefined) {
                    el.setAttribute('placeholder', el.dataset.origViFullPh);
                }
                if (el.dataset.origViTitle !== undefined) {
                    el.setAttribute('title', el.dataset.origViTitle);
                }
            });
        } finally {
            isMutatingI18nDom = false;
        }
    };

    const applyLanguage = (lang) => {
        const targetLang = (lang === 'en') ? 'en' : 'vi';
        currentUiLang = targetLang;
        langSessionToken += 1;
        const myToken = langSessionToken;

        document.documentElement.lang = targetLang;
        localStorage.setItem('xanhhub_lang', targetLang);

        const langBtn = document.getElementById('btn-lang-toggle');
        const badgeEl = document.getElementById('lang-current-badge');
        if (langBtn) langBtn.dataset.lang = targetLang;
        if (badgeEl) badgeEl.textContent = targetLang.toUpperCase();

        isMutatingI18nDom = true;
        try {
            applyKeyedElements(targetLang);
        } finally {
            isMutatingI18nDom = false;
        }

        if (targetLang === 'en') {
            collectAndApplyFullPageTranslation(myToken);
        } else {
            restoreAllVietnameseText();
        }
    };

    if (typeof MutationObserver !== 'undefined' && document.body) {
        const i18nMutationObserver = new MutationObserver(() => {
            if (currentUiLang !== 'en' || isMutatingI18nDom) return;
            clearTimeout(i18nObserverTimer);
            const activeToken = langSessionToken;
            i18nObserverTimer = setTimeout(() => {
                if (currentUiLang === 'en' && activeToken === langSessionToken) {
                    collectAndApplyFullPageTranslation(activeToken);
                }
            }, 180);
        });
        i18nMutationObserver.observe(document.body, { childList: true, subtree: true });
    }

    window.XanhHubI18n = { applyLanguage };

    const savedLang = localStorage.getItem('xanhhub_lang');
    if (savedLang === 'en') {
        applyLanguage('en');
    }

    const btnLangToggle = document.getElementById('btn-lang-toggle');
    if (btnLangToggle) {
        btnLangToggle.addEventListener('click', () => {
            const nextLang = (btnLangToggle.dataset.lang === 'en') ? 'vi' : 'en';
            applyLanguage(nextLang);
            if (window.XanhHubUI) {
                window.XanhHubUI.toast(
                    'success',
                    nextLang === 'en' ? 'Full Page Translated: English (EN)' : 'Ngôn ngữ: Tiếng Việt (VI)',
                    nextLang === 'en'
                        ? 'Entire interface is being translated to English via Xanh Hub API & cached.'
                        : 'Đã khôi phục toàn bộ giao diện & nội dung về Tiếng Việt chuẩn.'
                );
            }
        });
    }

    // Hộp Tiện ích Nhanh trên Header (#btn-header-utilities)
    const btnHeaderUtil = document.getElementById('btn-header-utilities');
    const utilDropdown = document.getElementById('header-utilities-dropdown');
    if (btnHeaderUtil && utilDropdown) {
        const closeUtilDropdown = () => {
            utilDropdown.classList.add('hidden');
            btnHeaderUtil.setAttribute('aria-expanded', 'false');
        };

        btnHeaderUtil.addEventListener('click', (e) => {
            e.stopPropagation();
            const isHidden = utilDropdown.classList.toggle('hidden');
            btnHeaderUtil.setAttribute('aria-expanded', String(!isHidden));
        });

        document.addEventListener('click', (e) => {
            if (!e.target.closest('#header-utilities-wrapper')) {
                closeUtilDropdown();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeUtilDropdown();
            }
        });
    }

    // Trợ năng: Thay đổi cỡ chữ toàn trang
    const applyFontScale = (pxSize) => {
        const clamped = Math.max(14, Math.min(18, pxSize));
        document.documentElement.style.fontSize = `${clamped}px`;
        localStorage.setItem('xanhhub_font_px', String(clamped));
    };
    const savedFontPx = parseFloat(localStorage.getItem('xanhhub_font_px') || '');
    if (!isNaN(savedFontPx) && savedFontPx >= 14 && savedFontPx <= 18) {
        document.documentElement.style.fontSize = `${savedFontPx}px`;
    }
    const btnFontDec = document.getElementById('util-font-dec');
    const btnFontReset = document.getElementById('util-font-reset');
    const btnFontInc = document.getElementById('util-font-inc');
    if (btnFontDec) btnFontDec.addEventListener('click', () => applyFontScale(14.5));
    if (btnFontReset) btnFontReset.addEventListener('click', () => {
        document.documentElement.style.fontSize = '';
        localStorage.removeItem('xanhhub_font_px');
    });
    if (btnFontInc) btnFontInc.addEventListener('click', () => applyFontScale(17.5));

    // Tiện ích GPS tức thời trong Hộp Tiện ích Header
    const utilBtnGps = document.getElementById('util-btn-gps-now');
    const utilGpsRes = document.getElementById('util-gps-result');
    if (utilBtnGps && utilGpsRes) {
        const resolveHeaderGps = (lat, lng, fallbackNote = '') => {
            fetch(`?action=api_reverse_geocode&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`)
                .then(r => r.json())
                .then(data => {
                    const addr = (data && data.ok && (data.full_address || (data.address && data.address.full_address)))
                        ? (data.full_address || data.address.full_address)
                        : 'Trung tâm TP. Hà Nội (Hồ Hoàn Kiếm)';
                    utilGpsRes.innerHTML = `
                        <div class="font-bold text-slate-900 dark:text-white">${escapeHtml(addr)}</div>
                        <div class="text-[10px] mono text-slate-500 mt-0.5">${Number(lat).toFixed(4)}° N, ${Number(lng).toFixed(4)}° E ${escapeHtml(fallbackNote)}</div>
                        <a href="?view=map&lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}&place=${encodeURIComponent(addr)}" class="inline-flex items-center gap-1 text-[10px] font-bold text-[#0057B8] dark:text-sky-400 hover:underline mt-1">
                            <span>Xem ca trực gần vị trí này trên Bản đồ &rarr;</span>
                        </a>
                    `;
                })
                .catch(() => {
                    utilGpsRes.textContent = `Tọa độ: ${Number(lat).toFixed(4)}° N, ${Number(lng).toFixed(4)}° E`;
                });
        };

        const runHeaderGpsLookup = () => {
            utilGpsRes.textContent = 'Đang lấy tọa độ GPS & giải mã địa chỉ Hà Nội...';
            if (!navigator.geolocation) {
                resolveHeaderGps(21.0285, 105.8542, '(Mặc định TP. Hà Nội)');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => resolveHeaderGps(pos.coords.latitude, pos.coords.longitude, ''),
                () => resolveHeaderGps(21.0285, 105.8542, '(Mặc định TP. Hà Nội)'),
                { enableHighAccuracy: true, timeout: 6000 }
            );
        };

        utilBtnGps.addEventListener('click', runHeaderGpsLookup);
        if (btnHeaderUtil && utilDropdown) {
            btnHeaderUtil.addEventListener('click', () => {
                if (!utilDropdown.classList.contains('hidden') && !utilBtnGps.dataset.autoChecked) {
                    utilBtnGps.dataset.autoChecked = '1';
                    runHeaderGpsLookup();
                }
            });
        }
    }

    // =========================================================================
    // 15. QUẢN LÝ BẢN TIN: MỞ MODAL CHỈNH SỬA & ĐỒNG BỘ REDIRECT THEO NGỮ CẢNH
    // =========================================================================
    const computeNewsRedirect = () => {
        const params = new URLSearchParams(window.location.search);
        const view = params.get('view') || '';
        if (view === 'coordinator') {
            const actId = params.get('activity_id') || '1';
            return `?view=coordinator&activity_id=${encodeURIComponent(actId)}&vtab=tab-news`;
        }
        if (view === 'admin') {
            return '?view=admin&tab=news#admin-tab-news';
        }
        if (view === 'news') {
            return '?view=news';
        }
        return '';
    };

    const syncNewsFormRedirects = () => {
        const defaultRedirect = computeNewsRedirect();
        if (!defaultRedirect) return;
        document.querySelectorAll('.news-form-redirect').forEach(inp => {
            if (!inp.value) inp.value = defaultRedirect;
        });
    };
    syncNewsFormRedirects();

    document.addEventListener('click', (e) => {
        const createNewsBtn = e.target.closest('[data-open-modal="modal-admin-create-news"]');
        if (createNewsBtn) {
            const createModal = document.getElementById('modal-admin-create-news');
            const redirInp = createModal ? createModal.querySelector('.news-form-redirect') : null;
            if (redirInp) {
                redirInp.value = createNewsBtn.dataset.newsRedirect || computeNewsRedirect();
            }
        }

        const editBtn = e.target.closest('.btn-edit-news-trigger');
        if (!editBtn) return;

        const idEl = document.getElementById('edit-news-id');
        const titleEl = document.getElementById('edit-news-title');
        const tagEl = document.getElementById('edit-news-tag');
        const actEl = document.getElementById('edit-news-act-id');
        const imgEl = document.getElementById('edit-news-img');
        const sumEl = document.getElementById('edit-news-summary');
        const contentEl = document.getElementById('edit-news-content');
        const statusEl = document.getElementById('edit-news-status');
        const dateEl = document.getElementById('edit-news-pub-date');
        const editModal = document.getElementById('modal-admin-edit-news');

        if (idEl) idEl.value = editBtn.dataset.newsId || '';
        if (titleEl) titleEl.value = editBtn.dataset.newsTitle || '';
        if (tagEl) tagEl.value = editBtn.dataset.newsTag || 'Bản tin';
        if (actEl) actEl.value = editBtn.dataset.newsActId || '';
        if (imgEl) imgEl.value = editBtn.dataset.newsImg || 'assets/images/campaign-d1.jpg';
        if (sumEl) sumEl.value = editBtn.dataset.newsSummary || '';
        if (contentEl) contentEl.value = editBtn.dataset.newsContent || '';
        if (statusEl) statusEl.value = editBtn.dataset.newsStatus || 'PUBLISHED';
        if (dateEl) dateEl.value = editBtn.dataset.newsPubDate || '';

        if (editModal) {
            const redirInp = editModal.querySelector('.news-form-redirect');
            if (redirInp) {
                redirInp.value = editBtn.dataset.newsRedirect || computeNewsRedirect();
            }
        }

        XanhHubUI.openModal('modal-admin-edit-news');
    });
})();


