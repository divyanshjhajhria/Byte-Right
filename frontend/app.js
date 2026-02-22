/**
 * ByteRight - Frontend API Integration
 * Connects all frontend pages to the PHP backend.
 */

const API_BASE = '../backend/api';

// ============================================
// UTILITY HELPERS
// ============================================

async function apiCall(endpoint, options = {}) {
    const url = `${API_BASE}/${endpoint}`;
    const defaults = {
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include', // send session cookies
    };

    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        options.body = JSON.stringify(options.body);
    }
    if (options.body instanceof FormData) {
        delete defaults.headers['Content-Type']; // let browser set multipart boundary
    }

    const res = await fetch(url, { ...defaults, ...options });
    const data = await res.json();

    if (!res.ok) {
        throw { status: res.status, ...data };
    }
    return data;
}

function showToast(message, type = 'success') {
    let toast = document.getElementById('byteright-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'byteright-toast';
        toast.style.cssText = `
            position: fixed; bottom: 24px; right: 24px; padding: 14px 24px;
            border-radius: 12px; color: #fff; font-weight: 600; font-size: 0.95rem;
            z-index: 9999; opacity: 0; transition: opacity 0.3s ease;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15); font-family: Inter, sans-serif;
        `;
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.style.background = type === 'success' ? '#4caf50' : type === 'error' ? '#e53935' : '#ff9800';
    toast.style.opacity = '1';
    setTimeout(() => { toast.style.opacity = '0'; }, 3500);
}

function timeAgo(dateStr) {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins} min ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs} hour${hrs > 1 ? 's' : ''} ago`;
    const days = Math.floor(hrs / 24);
    if (days < 7) return `${days} day${days > 1 ? 's' : ''} ago`;
    return new Date(dateStr).toLocaleDateString();
}

// ============================================
// AUTH CHECK - runs on every page except login
// ============================================

async function checkAuth() {
    try {
        const data = await apiCall('auth.php?action=status');
        if (!data.logged_in) {
            window.location.href = 'byteright_login.html';
            return null;
        }
        // Replace {username} placeholders on the page
        document.querySelectorAll('*').forEach(el => {
            if (el.children.length === 0 && el.textContent.includes('{username}')) {
                el.textContent = el.textContent.replace(/\{username\}/g, data.user.name);
            }
            if (el.value && el.value.includes('{username}')) {
                el.value = el.value.replace(/\{username\}/g, data.user.name);
            }
        });
        return data.user;
    } catch (e) {
        window.location.href = 'byteright_login.html';
        return null;
    }
}

// ============================================
// LOGIN PAGE
// ============================================

function initLoginPage() {
    const authPanel = document.querySelector('.auth-panel');
    if (!authPanel) return;

    // Override the existing login/signup form buttons
    document.addEventListener('click', async (e) => {
        if (e.target.classList.contains('btn-main')) {
            e.preventDefault();
            e.stopPropagation();

            const isSignup = document.querySelector('.auth-tab:last-child').classList.contains('active');
            const fields = authPanel.querySelectorAll('input');

            if (isSignup) {
                // Sign up
                const name = fields[0]?.value?.trim();
                const email = fields[1]?.value?.trim();
                const password = fields[2]?.value;
                const confirm = fields[3]?.value;

                if (!name || !email || !password) {
                    showToast('Please fill in all fields', 'error');
                    return;
                }
                if (password !== confirm) {
                    showToast('Passwords do not match', 'error');
                    return;
                }

                try {
                    await apiCall('auth.php?action=register', {
                        method: 'POST',
                        body: { name, email, password, confirm_password: confirm }
                    });
                    showToast('Account created! Redirecting...');
                    setTimeout(() => window.location.href = 'byteright_dashboard.html', 800);
                } catch (err) {
                    showToast(err.error || 'Registration failed', 'error');
                }
            } else {
                // Login
                const email = fields[0]?.value?.trim();
                const password = fields[1]?.value;

                if (!email || !password) {
                    showToast('Please enter email and password', 'error');
                    return;
                }

                try {
                    await apiCall('auth.php?action=login', {
                        method: 'POST',
                        body: { email, password }
                    });
                    showToast('Logging in...');
                    setTimeout(() => window.location.href = 'byteright_dashboard.html', 500);
                } catch (err) {
                    showToast(err.error || 'Invalid credentials', 'error');
                }
            }
        }
    });
}

// ============================================
// DASHBOARD PAGE
// ============================================

async function initDashboardPage() {
    const user = await checkAuth();
    if (!user) return;

    // Load stats
    try {
        const stats = await apiCall('profile.php?action=stats');
        const statValues = document.querySelectorAll('.stat-mini-value');
        if (statValues[0]) statValues[0].textContent = stats.recipes_saved;
        if (statValues[1]) statValues[1].textContent = `£${stats.total_saved}`;
        if (statValues[2]) statValues[2].textContent = stats.friends_count;
    } catch (e) { /* stats are optional */ }

    // Load saved recipes for "Recent Recipes" section
    try {
        const recipes = await apiCall('recipes.php?action=saved');
        const recentList = document.querySelector('.recent-list');
        if (recentList && recipes.length > 0) {
            const icons = ['🍝', '🥘', '🍳', '🍲', '🥗'];
            recentList.innerHTML = recipes.slice(0, 3).map((r, i) => `
                <div class="recent-item">
                    <div class="recent-icon">${icons[i % icons.length]}</div>
                    <div class="recent-info">
                        <div class="recent-title">${escapeHtml(r.title)}</div>
                        <div class="recent-meta">Saved ${timeAgo(r.saved_at)}</div>
                    </div>
                    <button class="btn-mini" onclick="window.location.href='byteright_recipes.html'">Cook Again</button>
                </div>
            `).join('');
        }
    } catch (e) { /* keep placeholder data */ }

    // Load current meal plan preview
    try {
        const plan = await apiCall('mealplan.php?action=current');
        const weekPreview = document.querySelector('.week-preview');
        if (weekPreview && plan.items) {
            const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            const today = new Date().getDay();
            const dinnerItems = plan.items.filter(i => i.meal_type === 'dinner');
            weekPreview.innerHTML = dinnerItems.slice(0, 4).map((item, i) => {
                const label = i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : days[(today + i) % 7];
                return `
                    <div class="week-day">
                        <div class="week-day-label">${label}</div>
                        <div class="week-day-meal">${escapeHtml(item.recipe_title || item.custom_meal_name || 'No meal')}</div>
                    </div>
                `;
            }).join('');
        }
    } catch (e) { /* keep placeholder data */ }

    // Fridge inventory widget
    await initFridgeWidget();

    // Logout link
    document.querySelectorAll('a[href="byteright_login.html"]').forEach(a => {
        a.addEventListener('click', async (e) => {
            e.preventDefault();
            await apiCall('auth.php?action=logout');
            window.location.href = 'byteright_login.html';
        });
    });
}

async function initFridgeWidget() {
    const listEl = document.getElementById('fridgeItemsList');
    const input = document.getElementById('fridgeItemInput');
    const addBtn = document.getElementById('addFridgeBtn');
    if (!listEl || !input || !addBtn) return;

    async function loadFridge() {
        try {
            const data = await apiCall('fridge.php?action=list');
            if (data.items.length === 0) {
                listEl.innerHTML = '<span style="color:#8d7b68;font-size:0.85rem;">No items yet. Add what you have.</span>';
                return;
            }
            listEl.innerHTML = data.items.map(item => `
                <span class="fridge-chip" style="
                    display:inline-flex;align-items:center;gap:4px;
                    background:#f0ebe3;border:1px solid #d4c9b8;border-radius:16px;
                    padding:4px 10px;font-size:0.82rem;color:#5d4a3a;">
                    ${escapeHtml(item.name)}${item.quantity ? ' (' + escapeHtml(item.quantity) + ')' : ''}
                    <button onclick="removeFridgeItem(${item.id})" style="
                        background:none;border:none;cursor:pointer;color:#999;font-size:0.9rem;
                        padding:0 2px;line-height:1;">&times;</button>
                </span>
            `).join('');
        } catch (e) { /* keep default */ }
    }

    addBtn.addEventListener('click', async () => {
        const name = input.value.trim();
        if (!name) return;
        try {
            await apiCall('fridge.php?action=add', {
                method: 'POST',
                body: { name }
            });
            input.value = '';
            await loadFridge();
        } catch (err) {
            showToast(err.error || 'Could not add item', 'error');
        }
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') addBtn.click();
    });

    await loadFridge();
}

// Global function for inline onclick
async function removeFridgeItem(id) {
    try {
        await apiCall('fridge.php?action=remove&id=' + id, { method: 'DELETE' });
        await initFridgeWidget();
    } catch (e) { /* ignore */ }
}

// ============================================
// RECIPES PAGE
// ============================================

async function initRecipesPage() {
    await checkAuth();

    const textarea = document.querySelector('textarea');
    const chips = document.querySelectorAll('.chip');
    const filterPills = document.querySelectorAll('.filter-pill');
    const generateBtn = document.querySelector('.btn-gen');
    const recipeList = document.querySelector('.recipe-list');
    const resultsHeader = document.querySelector('.results-header small');

    // Pre-load fridge items into textarea
    try {
        const fridge = await apiCall('fridge.php?action=list');
        if (fridge.items.length > 0 && textarea) {
            textarea.value = fridge.items.map(i => i.name).join(', ');
        }
    } catch (e) { /* ignore */ }

    let activeFilters = { diet: '', maxTime: 0, maxCost: 0 };

    // Chip click adds to textarea
    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            const name = chip.textContent.trim().replace(/^[^\w]+/, '').trim();
            const current = textarea.value.trim();
            if (current && !current.endsWith(',')) {
                textarea.value = current + ', ' + name;
            } else {
                textarea.value = (current ? current + ' ' : '') + name;
            }
            chip.style.opacity = '0.5';
        });
    });

    // Filter pills
    filterPills.forEach(pill => {
        pill.addEventListener('click', () => {
            pill.classList.toggle('active');
            const text = pill.textContent;
            if (text.includes('Vegetarian')) {
                activeFilters.diet = pill.classList.contains('active') ? 'vegetarian' : '';
            } else if (text.includes('20 min')) {
                activeFilters.maxTime = pill.classList.contains('active') ? 20 : 0;
            } else if (text.includes('£5')) {
                activeFilters.maxCost = pill.classList.contains('active') ? 5 : 0;
            }
        });
    });

    // Generate button
    generateBtn.addEventListener('click', async () => {
        const ingredients = textarea.value.trim();
        if (!ingredients) {
            showToast('Please enter at least one ingredient', 'error');
            return;
        }

        generateBtn.textContent = 'Searching...';
        generateBtn.disabled = true;

        try {
            const params = new URLSearchParams({
                action: 'search',
                ingredients,
                diet: activeFilters.diet,
                maxTime: activeFilters.maxTime,
                maxCost: activeFilters.maxCost,
            });
            const data = await apiCall(`recipes.php?${params}`);

            if (resultsHeader) {
                resultsHeader.textContent = `Showing ${data.count} matches (${data.source === 'api' ? 'Spoonacular API' : 'local library'})`;
            }

            if (data.recipes.length === 0) {
                recipeList.innerHTML = '<p style="text-align:center;padding:24px;color:#6d4c41;">No recipes found. Try different ingredients or remove filters.</p>';
            } else {
                recipeList.innerHTML = data.recipes.map(r => {
                    const totalTime = (r.prep_time || 0) + (r.cook_time || 0);
                    const icons = ['🍳', '🥘', '🍝', '🍲', '🥗', '🍛'];
                    const icon = icons[Math.floor(Math.random() * icons.length)];
                    return `
                        <article class="recipe-card" data-id="${r.id || ''}" style="cursor:${r.id ? 'pointer' : 'default'};">
                            <div class="recipe-icon">${icon}</div>
                            <div class="recipe-main">
                                <h3>${escapeHtml(r.title)}</h3>
                                <p>Uses: ${(r.used_ingredients || []).join(', ') || 'various'}</p>
                                ${r.missed_ingredients?.length ? `<p style="font-size:0.8rem;color:#999">Also needs: ${r.missed_ingredients.slice(0,3).join(', ')}</p>` : ''}
                                <div class="recipe-tags">
                                    ${totalTime ? `<span class="tag">${totalTime} min</span>` : ''}
                                    ${r.estimated_cost ? `<span class="tag">£${parseFloat(r.estimated_cost).toFixed(2)}</span>` : ''}
                                    ${r.difficulty ? `<span class="tag">${r.difficulty}</span>` : ''}
                                </div>
                            </div>
                            <div class="recipe-meta">
                                <strong>${r.match_percentage || 0}% match</strong>
                                ${r.id ? `<button class="btn-save-recipe" data-recipe-id="${r.id}" style="
                                    background:#7cb342;color:#fff;border:none;padding:6px 12px;
                                    border-radius:8px;cursor:pointer;font-size:0.8rem;margin-top:6px;
                                ">Save</button>` : ''}
                            </div>
                        </article>
                    `;
                }).join('');

                // Save recipe buttons
                recipeList.querySelectorAll('.btn-save-recipe').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.stopPropagation(); // don't open modal when clicking save
                        try {
                            await apiCall(`recipes.php?action=save&recipe_id=${btn.dataset.recipeId}`, { method: 'POST' });
                            btn.textContent = 'Saved!';
                            btn.disabled = true;
                            showToast('Recipe saved!');
                        } catch (err) {
                            showToast(err.error || 'Could not save', 'error');
                        }
                    });
                });

                // Click recipe card to open detail modal
                recipeList.querySelectorAll('.recipe-card[data-id]').forEach(card => {
                    const id = card.dataset.id;
                    if (id) {
                        card.addEventListener('click', () => openRecipeModal(id));
                    }
                });
            }
        } catch (err) {
            showToast(err.error || 'Search failed', 'error');
        } finally {
            generateBtn.textContent = '✨ Generate Recipes';
            generateBtn.disabled = false;
        }
    });
}

// ============================================
// MEAL PLANNER PAGE
// ============================================

async function initPlannerPage() {
    const user = await checkAuth();
    if (!user) return;

    const grid = document.querySelector('.grid');
    const aside = document.querySelector('aside.panel');

    // Add generate button before the grid
    const genBtnContainer = document.createElement('div');
    genBtnContainer.style.cssText = 'display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center;';
    genBtnContainer.innerHTML = `
        <button id="genPlanBtn" style="
            background: linear-gradient(135deg, #7cb342, #689f38); color: #fff;
            border: none; padding: 12px 24px; border-radius: 12px; cursor: pointer;
            font-weight: 600; font-size: 0.95rem; font-family: Inter, sans-serif;
        ">Generate My Week</button>
        <label style="font-size:0.9rem;color:#5d4a3a;">Budget: £<input id="planBudget" type="number" value="30" min="10" max="200" step="5" style="
            width:60px;padding:6px;border-radius:8px;border:1px solid #c9c4b0;text-align:center;
        "> / week</label>
    `;

    const panel = grid.closest('.panel') || grid.parentElement;
    panel.insertBefore(genBtnContainer, grid);

    const genPlanBtn = document.getElementById('genPlanBtn');
    const planBudget = document.getElementById('planBudget');

    // Load user's saved budget from profile
    try {
        const profile = await apiCall('profile.php');
        if (profile.weekly_budget) {
            planBudget.value = parseFloat(profile.weekly_budget);
        }
    } catch (e) { /* keep default */ }

    // Try loading existing plan
    await loadCurrentPlan(grid, aside);

    // Generate new plan
    genPlanBtn.addEventListener('click', async () => {
        genPlanBtn.textContent = 'Generating...';
        genPlanBtn.disabled = true;
        try {
            const budgetVal = parseFloat(planBudget.value) || 30;
            const plan = await apiCall('mealplan.php?action=generate', {
                method: 'POST',
                body: { budget: budgetVal }
            });
            // Also save the budget to the user's profile so it persists
            try {
                await apiCall('profile.php?action=update', {
                    method: 'POST',
                    body: { weekly_budget: budgetVal }
                });
            } catch (e) { /* non-critical */ }
            renderMealPlan(grid, plan);
            await loadShoppingList(aside, plan.id);
            showToast('Meal plan generated!');
        } catch (err) {
            showToast(err.error || 'Failed to generate plan', 'error');
        } finally {
            genPlanBtn.textContent = 'Generate My Week';
            genPlanBtn.disabled = false;
        }
    });
}

async function loadCurrentPlan(grid, aside) {
    try {
        const plan = await apiCall('mealplan.php?action=current');
        renderMealPlan(grid, plan);
        await loadShoppingList(aside, plan.id);
    } catch (e) {
        // No current plan - keep placeholder
    }
}

function renderMealPlan(grid, plan) {
    if (!plan || !plan.items) return;

    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    // Detect which meal types are in the plan
    const hasLunch = plan.items.some(i => i.meal_type === 'lunch');
    const mealTypes = hasLunch ? ['breakfast', 'lunch', 'dinner'] : ['breakfast', 'dinner'];

    let html = '<div></div>';
    days.forEach(d => html += `<div class="grid-header">${d}</div>`);

    mealTypes.forEach(type => {
        html += `<div class="time-label">${type.charAt(0).toUpperCase() + type.slice(1)}</div>`;
        for (let day = 0; day < 7; day++) {
            const item = plan.items.find(i => i.day_of_week == day && i.meal_type === type);
            const title = item?.recipe_title || item?.custom_meal_name || '-';
            const cost = item?.estimated_cost ? `£${parseFloat(item.estimated_cost).toFixed(2)}` : '';
            const time = item?.cook_time ? `${item.cook_time} min` : '';
            const meta = [cost, time].filter(Boolean).join(' · ');
            const recipeId = item?.recipe_id;
            const clickable = recipeId ? `onclick="openRecipeModal(${recipeId})" style="cursor:pointer;"` : '';
            html += `<div class="cell" ${clickable}><div class="cell-title">${escapeHtml(title)}</div><div class="cell-meta">${meta}</div></div>`;
        }
    });

    grid.innerHTML = html;
}

async function loadShoppingList(aside, mealPlanId) {
    try {
        // Generate shopping list from the plan
        const list = await apiCall(`shopping.php?action=generate&meal_plan_id=${mealPlanId}`, { method: 'POST' });
        renderShoppingList(aside, list);
    } catch (e) {
        // Try loading existing list
        try {
            const list = await apiCall('shopping.php?action=current');
            renderShoppingList(aside, list);
        } catch (e2) {
            // Keep placeholder
        }
    }
}

function renderShoppingList(aside, list) {
    if (!list || !list.items_grouped) return;

    const categoryNames = {
        fresh_produce: 'Fresh produce',
        store_cupboard: 'Store cupboard',
        fridge_freezer: 'Fridge & freezer',
        other: 'Other',
    };

    let html = `
        <div class="summary-title">Auto-generated shopping list</div>
        <div class="summary-block">
            ByteRight scans your week and combines overlapping ingredients into one simple list,
            so you only buy what you actually need.
        </div>
    `;

    for (const [category, items] of Object.entries(list.items_grouped)) {
        if (items.length === 0) continue;
        html += `<div class="list"><h3>${categoryNames[category] || category}</h3><ul>`;
        items.forEach(item => {
            const qty = [item.quantity, item.unit].filter(Boolean).join(' ');
            html += `<li>
                <span>${escapeHtml(item.ingredient_name)}</span>
                <span class="qty">${escapeHtml(qty)}</span>
            </li>`;
        });
        html += '</ul></div>';
    }

    html += `
        <div class="saving-card">
            <strong>Estimated cost: £${list.calculated_total?.toFixed(2) || list.estimated_total}</strong>
            <span>Based on average supermarket prices.</span>
        </div>
    `;

    aside.innerHTML = html;
}

// ============================================
// SOCIAL PAGE
// ============================================

async function initSocialPage() {
    const user = await checkAuth();
    if (!user) return;

    const postsList = document.querySelector('.posts-list');
    const createPostCard = document.getElementById('createPostCard');
    const createPostBtn = document.querySelector('.btn-create-post');
    const cancelPostBtn = document.querySelector('.btn-cancel-post');
    const submitPostBtn = document.querySelector('.btn-submit-post');
    const uploadPhotoBtn = document.querySelector('.btn-upload-photo');

    // Load feed from backend
    await loadFeed(postsList, user);

    // Load sidebar stats
    await loadSocialStats();

    // Create post toggle
    createPostBtn.addEventListener('click', () => createPostCard.classList.toggle('hidden'));
    cancelPostBtn.addEventListener('click', () => createPostCard.classList.add('hidden'));

    // File input for photo upload
    let selectedFile = null;
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
    fileInput.style.display = 'none';
    document.body.appendChild(fileInput);

    uploadPhotoBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) {
            selectedFile = fileInput.files[0];
            uploadPhotoBtn.textContent = `📷 ${selectedFile.name}`;
        }
    });

    // Submit post
    submitPostBtn.addEventListener('click', async () => {
        const textarea = createPostCard.querySelector('.post-input');
        const content = textarea.value.trim();

        if (!content) {
            showToast('Please write something!', 'error');
            return;
        }

        submitPostBtn.textContent = 'Posting...';
        submitPostBtn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('content', content);
            if (selectedFile) {
                formData.append('image', selectedFile);
            }

            await apiCall('social.php?action=create', {
                method: 'POST',
                body: formData,
            });

            showToast('Post shared!');
            textarea.value = '';
            selectedFile = null;
            uploadPhotoBtn.textContent = '📷 Add Photo';
            createPostCard.classList.add('hidden');

            // Reload feed
            await loadFeed(postsList, user);
        } catch (err) {
            showToast(err.error || 'Failed to post', 'error');
        } finally {
            submitPostBtn.textContent = 'Post to Feed';
            submitPostBtn.disabled = false;
        }
    });
}

async function loadFeed(container, user) {
    try {
        const data = await apiCall('social.php?action=feed');

        if (data.posts.length === 0) {
            container.innerHTML = '<p style="text-align:center;padding:32px;color:#6d4c41;">No posts yet. Be the first to share something!</p>';
            return;
        }

        container.innerHTML = data.posts.map(post => {
            const liked = post.liked_by_me > 0;
            const isOwn = post.user_id == user?.id;
            return `
                <article class="post-card" data-post-id="${post.id}">
                    <div class="post-header">
                        <div class="user-avatar">${post.author_avatar || '👤'}</div>
                        <div class="post-user-info">
                            <div class="post-username">${escapeHtml(post.author_name)}</div>
                            <div class="post-time">${timeAgo(post.created_at)}</div>
                        </div>
                        ${isOwn ? `<button class="btn-delete-post" data-post-id="${post.id}" style="
                            background:#ffebee;color:#c62828;border:1px solid #ef9a9a;
                            padding:5px 12px;border-radius:8px;cursor:pointer;
                            font-size:0.8rem;font-weight:600;font-family:Inter,sans-serif;
                        ">Delete</button>` : ''}
                    </div>
                    <div class="post-content">
                        <p>${escapeHtml(post.content)}</p>
                    </div>
                    ${post.image_path ? `<div class="post-image"><img src="../backend/${post.image_path}" alt="Post image" style="width:100%;border-radius:12px;"></div>` : ''}
                    ${post.recipe_title ? `
                        <div class="post-recipe-tag">
                            <span class="recipe-link">Recipe: ${escapeHtml(post.recipe_title)}</span>
                            <span class="recipe-cost">${post.recipe_cost ? '£' + parseFloat(post.recipe_cost).toFixed(2) : ''} ${post.recipe_time ? '• ' + post.recipe_time + ' min' : ''}</span>
                        </div>
                    ` : ''}
                    <div class="post-stats">
                        <span class="likes-count">${post.likes_count} like${post.likes_count !== 1 ? 's' : ''}</span>
                        <span class="comments-count">${post.comments_count} comment${post.comments_count !== 1 ? 's' : ''}</span>
                    </div>
                    <div class="post-actions-bar">
                        <button class="action-btn ${liked ? 'active' : ''}" data-action="like" data-post-id="${post.id}">
                            👍 ${liked ? 'Liked' : 'Like'}
                        </button>
                        <button class="action-btn" data-action="comment" data-post-id="${post.id}">💬 Comment</button>
                        <button class="action-btn" data-action="save" data-post-id="${post.id}">🔖 Save</button>
                    </div>
                    <div class="comments-section" id="comments-${post.id}" style="display:none;padding:12px 0 0 0;border-top:1px solid #e0d6c8;margin-top:12px;">
                        <div class="comments-list" style="max-height:200px;overflow-y:auto;margin-bottom:10px;"></div>
                        <div style="display:flex;gap:8px;">
                            <input type="text" class="comment-input" placeholder="Write a comment..." style="
                                flex:1;padding:8px 14px;border:1px solid #c9c4b0;border-radius:10px;
                                font-size:0.85rem;font-family:Inter,sans-serif;background:#fffaf0;
                            ">
                            <button class="btn-send-comment" data-post-id="${post.id}" style="
                                background:#7cb342;color:#fff;border:none;padding:8px 16px;
                                border-radius:10px;cursor:pointer;font-weight:600;font-size:0.85rem;
                            ">Send</button>
                        </div>
                    </div>
                </article>
            `;
        }).join('');

        // Like buttons
        container.querySelectorAll('[data-action="like"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const postId = btn.dataset.postId;
                const isLiked = btn.classList.contains('active');
                try {
                    await apiCall(`social.php?action=${isLiked ? 'unlike' : 'like'}&post_id=${postId}`, { method: 'POST' });
                    btn.classList.toggle('active');
                    btn.innerHTML = btn.classList.contains('active') ? '👍 Liked' : '👍 Like';
                    const statsEl = btn.closest('.post-card').querySelector('.likes-count');
                    const currentCount = parseInt(statsEl.textContent) || 0;
                    const newCount = isLiked ? Math.max(0, currentCount - 1) : currentCount + 1;
                    statsEl.textContent = `${newCount} like${newCount !== 1 ? 's' : ''}`;
                } catch (err) {
                    showToast(err.error || 'Error', 'error');
                }
            });
        });

        // Comment buttons - toggle comment section and load comments
        container.querySelectorAll('[data-action="comment"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const postId = btn.dataset.postId;
                const section = document.getElementById(`comments-${postId}`);
                const isVisible = section.style.display !== 'none';

                if (isVisible) {
                    section.style.display = 'none';
                    return;
                }

                section.style.display = 'block';
                const commentsList = section.querySelector('.comments-list');
                commentsList.innerHTML = '<p style="color:#999;font-size:0.8rem;text-align:center;">Loading...</p>';

                try {
                    const comments = await apiCall(`social.php?action=comments&post_id=${postId}`);
                    if (comments.length === 0) {
                        commentsList.innerHTML = '<p style="color:#999;font-size:0.8rem;text-align:center;">No comments yet. Be the first!</p>';
                    } else {
                        commentsList.innerHTML = comments.map(c => `
                            <div style="margin-bottom:10px;padding:8px 12px;background:#fdf9ea;border-radius:10px;">
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                    <strong style="font-size:0.85rem;color:#3e2723;">${escapeHtml(c.author_name)}</strong>
                                    <span style="font-size:0.75rem;color:#999;">${timeAgo(c.created_at)}</span>
                                </div>
                                <p style="margin:0;font-size:0.85rem;color:#4a3728;">${escapeHtml(c.content)}</p>
                            </div>
                        `).join('');
                    }
                } catch (err) {
                    commentsList.innerHTML = '<p style="color:#e53935;font-size:0.8rem;">Failed to load comments.</p>';
                }
            });
        });

        // Send comment buttons
        container.querySelectorAll('.btn-send-comment').forEach(btn => {
            btn.addEventListener('click', async () => {
                const postId = btn.dataset.postId;
                const section = document.getElementById(`comments-${postId}`);
                const input = section.querySelector('.comment-input');
                const content = input.value.trim();

                if (!content) return;

                btn.disabled = true;
                try {
                    await apiCall('social.php?action=comment', {
                        method: 'POST',
                        body: { post_id: parseInt(postId), content }
                    });
                    input.value = '';

                    // Update comment count in stats
                    const statsEl = btn.closest('.post-card').querySelector('.comments-count');
                    const currentCount = parseInt(statsEl.textContent) || 0;
                    statsEl.textContent = `${currentCount + 1} comment${currentCount + 1 !== 1 ? 's' : ''}`;

                    // Reload comments
                    const commentsList = section.querySelector('.comments-list');
                    const comments = await apiCall(`social.php?action=comments&post_id=${postId}`);
                    commentsList.innerHTML = comments.map(c => `
                        <div style="margin-bottom:10px;padding:8px 12px;background:#fdf9ea;border-radius:10px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <strong style="font-size:0.85rem;color:#3e2723;">${escapeHtml(c.author_name)}</strong>
                                <span style="font-size:0.75rem;color:#999;">${timeAgo(c.created_at)}</span>
                            </div>
                            <p style="margin:0;font-size:0.85rem;color:#4a3728;">${escapeHtml(c.content)}</p>
                        </div>
                    `).join('');
                    // Scroll to bottom
                    commentsList.scrollTop = commentsList.scrollHeight;
                } catch (err) {
                    showToast(err.error || 'Failed to comment', 'error');
                } finally {
                    btn.disabled = false;
                }
            });
        });

        // Also allow Enter key to send comments
        container.querySelectorAll('.comment-input').forEach(input => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    input.parentElement.querySelector('.btn-send-comment').click();
                }
            });
        });

        // Delete buttons
        container.querySelectorAll('.btn-delete-post').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Delete this post?')) return;
                const postId = btn.dataset.postId;
                try {
                    await apiCall(`social.php?action=delete&post_id=${postId}`, { method: 'DELETE' });
                    btn.closest('.post-card').remove();
                    showToast('Post deleted');
                } catch (err) {
                    showToast(err.error || 'Error', 'error');
                }
            });
        });

    } catch (e) {
        // Keep placeholder posts
    }
}

async function loadSocialStats() {
    try {
        const stats = await apiCall('profile.php?action=stats');
        const statValues = document.querySelectorAll('.sidebar-card .stat-value');
        if (statValues[0]) statValues[0].textContent = stats.posts_count;
        if (statValues[1]) statValues[1].textContent = stats.likes_received;
        if (statValues[2]) statValues[2].textContent = stats.recipes_saved;
        if (statValues[3]) statValues[3].textContent = `${stats.friends_count} friends`;
    } catch (e) { /* keep placeholder */ }
}

// ============================================
// PROFILE PAGE
// ============================================

async function initProfilePage() {
    const user = await checkAuth();
    if (!user) return;

    const db = {};

    // Load full profile
    try {
        const profile = await apiCall('profile.php');
        db.profile = profile;

        // Fill in personal info
        const nameInput = document.getElementById('nameInput');
        const emailInput = document.querySelector('.setting-item input[type="email"]');
        const uniInput = document.querySelectorAll('.settings-section')[0]?.querySelectorAll('.setting-item input')[2];

        if (nameInput) nameInput.value = profile.name;
        if (emailInput) emailInput.value = profile.email;
        if (uniInput) uniInput.value = profile.university || '';

        // Dietary prefs
        const prefMap = { 1: 'vegCheck', 2: 'veganCheck', 3: 'gfCheck', 4: 'dfCheck', 5: 'halalCheck', 6: 'kosherCheck' };
        (profile.dietary_preferences || []).forEach(p => {
            const checkbox = document.getElementById(prefMap[p.id]);
            if (checkbox) checkbox.checked = true;
        });

        // Allergies
        const allergiesInput = document.getElementById('allergiesInput');
        if (allergiesInput && profile.allergies) allergiesInput.value = profile.allergies;

        // Likes / dislikes
        const likedInput = document.getElementById('likedIngredientsInput');
        const dislikedInput = document.getElementById('dislikedIngredientsInput');
        if (likedInput && profile.liked_ingredients) likedInput.value = profile.liked_ingredients;
        if (dislikedInput && profile.disliked_ingredients) dislikedInput.value = profile.disliked_ingredients;

        // Budget
        const budgetInput = document.getElementById('budgetInput');
        const budgetSlider = document.getElementById('budgetSlider');
        if (budgetInput && profile.weekly_budget) {
            budgetInput.value = parseFloat(profile.weekly_budget);
            budgetSlider.value = parseFloat(profile.weekly_budget);
        }

        // Cooking time
        const timeMap = { under15: 'quick', under30: 'moderate', under60: 'long', any: 'any' };
        const cookingTimeSelect = document.getElementById('cookingTimeSelect');
        if (cookingTimeSelect && profile.cooking_time_pref) {
            cookingTimeSelect.value = timeMap[profile.cooking_time_pref] || 'moderate';
        }
    } catch (e) { /* keep defaults */ }

    // Load stats
    try {
        const stats = await apiCall('profile.php?action=stats');
        const profileStats = document.querySelector('.profile-stats');
        if (profileStats) {
            profileStats.innerHTML = `
                <span>${stats.recipes_saved} Recipes Saved</span>
                <span>·</span>
                <span>${stats.plans_count} Weeks Planned</span>
                <span>·</span>
                <span>${stats.friends_count} Friends</span>
            `;
        }

        // Activity tab stats
        const statCards = document.querySelectorAll('.stat-card-value');
        if (statCards[0]) statCards[0].textContent = `£${stats.total_saved}`;
        if (statCards[1]) statCards[1].textContent = stats.recipes_saved;
        if (statCards[2]) statCards[2].textContent = stats.posts_count;
    } catch (e) { /* keep defaults */ }

    // Load activity
    try {
        const activity = await apiCall('profile.php?action=activity');
        const activityList = document.querySelector('.activity-list');
        if (activityList && activity.length > 0) {
            const iconMap = {
                recipe_saved: '💾', recipe_cooked: '🍳', post_created: '📸',
                plan_created: '📅', friend_added: '👥'
            };
            activityList.innerHTML = activity.map(a => `
                <div class="activity-item">
                    <div class="activity-icon">${iconMap[a.action_type] || '📋'}</div>
                    <div class="activity-content">
                        <div class="activity-text">${escapeHtml(a.description)}</div>
                        <div class="activity-time">${timeAgo(a.created_at)}</div>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) { /* keep defaults */ }

    // Load friends
    try {
        const friendsData = await apiCall('friends.php?action=list');
        const friendsList = document.getElementById('friendsList');
        const friendsHeader = document.querySelector('.friends-section .section-header h2');
        if (friendsHeader) friendsHeader.textContent = `My Friends (${friendsData.count})`;

        if (friendsList && friendsData.friends.length > 0) {
            friendsList.innerHTML = friendsData.friends.map(f => `
                <div class="friend-item" data-friend-id="${f.id}">
                    <div class="friend-avatar">${f.avatar_path || '👤'}</div>
                    <div class="friend-info">
                        <div class="friend-name">${escapeHtml(f.name)}</div>
                        <div class="friend-meta">${f.recent_posts > 0 ? f.recent_posts + ' posts this week' : 'Friends since ' + timeAgo(f.friends_since)}</div>
                    </div>
                    <button class="btn-friend-action" onclick="window.location.href='byteright_social.html'">View</button>
                </div>
            `).join('');
        } else if (friendsList) {
            friendsList.innerHTML = '<div class="empty-state"><p>No friends yet. Add friends by email above.</p></div>';
        }
    } catch (e) { /* keep defaults */ }

    // Load friend requests
    try {
        const pending = await apiCall('friends.php?action=pending');
        const requestsList = document.getElementById('requestsList');
        const requestsEmpty = document.getElementById('requestsEmpty');
        const requestsHeader = document.querySelector('.requests-section h2');

        if (requestsHeader) requestsHeader.textContent = `Friend Requests (${pending.incoming.length})`;

        if (pending.incoming.length === 0) {
            if (requestsList) requestsList.classList.add('hidden');
            if (requestsEmpty) requestsEmpty.classList.remove('hidden');
        } else if (requestsList) {
            requestsList.innerHTML = pending.incoming.map(r => `
                <div class="request-item" data-request-id="${r.request_id}">
                    <div class="request-avatar">${r.avatar_path || '👤'}</div>
                    <div class="request-info">
                        <div class="request-name">${escapeHtml(r.name)}</div>
                        <div class="request-meta">Sent ${timeAgo(r.created_at)}</div>
                    </div>
                    <div class="request-actions">
                        <button class="btn-accept">✓ Accept</button>
                        <button class="btn-decline">✗ Decline</button>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) { /* keep defaults */ }

    // ---- Wire up actions ----

    // Save All button
    const saveAllBtn = document.getElementById('saveAllBtn');
    saveAllBtn.addEventListener('click', async () => {
        const timeReverseMap = { quick: 'under15', moderate: 'under30', long: 'under60', any: 'any' };

        const prefIdMap = { vegCheck: 1, veganCheck: 2, gfCheck: 3, dfCheck: 4, halalCheck: 5, kosherCheck: 6 };
        const selectedPrefs = [];
        for (const [checkId, prefId] of Object.entries(prefIdMap)) {
            if (document.getElementById(checkId)?.checked) selectedPrefs.push(prefId);
        }

        try {
            await apiCall('profile.php?action=update', {
                method: 'POST',
                body: {
                    name: document.getElementById('nameInput').value.trim(),
                    university: document.querySelectorAll('.settings-section')[0]?.querySelectorAll('.setting-item input')[2]?.value || '',
                    weekly_budget: parseFloat(document.getElementById('budgetInput').value) || 30,
                    cooking_time_pref: timeReverseMap[document.getElementById('cookingTimeSelect').value] || 'under30',
                    allergies: document.getElementById('allergiesInput').value,
                    liked_ingredients: document.getElementById('likedIngredientsInput')?.value || '',
                    disliked_ingredients: document.getElementById('dislikedIngredientsInput')?.value || '',
                    dietary_preference_ids: selectedPrefs,
                }
            });
            showToast('All changes saved!');
            document.getElementById('displayName').textContent = document.getElementById('nameInput').value.trim();
        } catch (err) {
            showToast(err.error || 'Save failed', 'error');
        }
    });

    // Change password
    const changePasswordBtn = document.getElementById('changePasswordBtn');
    changePasswordBtn.addEventListener('click', async () => {
        const current = document.getElementById('currentPassword').value;
        const newPass = document.getElementById('newPassword').value;
        const confirm = document.getElementById('confirmPassword').value;

        if (!current) { showToast('Enter current password', 'error'); return; }
        if (!newPass || newPass.length < 8) { showToast('New password must be at least 8 characters', 'error'); return; }
        if (newPass !== confirm) { showToast('Passwords do not match', 'error'); return; }

        try {
            await apiCall('profile.php?action=password', {
                method: 'POST',
                body: { current_password: current, new_password: newPass, confirm_password: confirm }
            });
            showToast('Password changed!');
            document.getElementById('currentPassword').value = '';
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
        } catch (err) {
            showToast(err.error || 'Password change failed', 'error');
        }
    });

    // Send friend request
    const sendRequestBtn = document.getElementById('sendRequestBtn');
    sendRequestBtn.addEventListener('click', async () => {
        const email = document.getElementById('friendEmailInput').value.trim();
        if (!email) { showToast('Enter an email', 'error'); return; }

        try {
            const result = await apiCall('friends.php?action=request', {
                method: 'POST',
                body: { email }
            });
            showToast(result.message || 'Request sent!');
            document.getElementById('addFriendForm').classList.add('hidden');
            document.getElementById('friendEmailInput').value = '';
        } catch (err) {
            showToast(err.error || 'Could not send request', 'error');
        }
    });

    // Accept/decline friend requests (event delegation)
    const requestsList = document.getElementById('requestsList');
    requestsList.addEventListener('click', async (e) => {
        const requestItem = e.target.closest('.request-item');
        if (!requestItem) return;
        const requestId = requestItem.dataset.requestId;

        if (e.target.classList.contains('btn-accept')) {
            try {
                await apiCall(`friends.php?action=accept&request_id=${requestId}`, { method: 'POST' });
                requestItem.remove();
                showToast('Friend added!');
            } catch (err) {
                showToast(err.error || 'Error', 'error');
            }
        }
        if (e.target.classList.contains('btn-decline')) {
            try {
                await apiCall(`friends.php?action=decline&request_id=${requestId}`, { method: 'POST' });
                requestItem.remove();
                showToast('Request declined');
            } catch (err) {
                showToast(err.error || 'Error', 'error');
            }
        }

        // Update count
        const remaining = requestsList.querySelectorAll('.request-item').length;
        const header = document.querySelector('.requests-section h2');
        if (header) header.textContent = `Friend Requests (${remaining})`;
        if (remaining === 0) {
            requestsList.classList.add('hidden');
            document.getElementById('requestsEmpty')?.classList.remove('hidden');
        }
    });

    // Logout links
    document.querySelectorAll('a[href="byteright_login.html"]').forEach(a => {
        a.addEventListener('click', async (e) => {
            e.preventDefault();
            await apiCall('auth.php?action=logout');
            window.location.href = 'byteright_login.html';
        });
    });
}

// ============================================
// RECIPE DETAIL MODAL
// ============================================

function openRecipeModal(recipeId) {
    let overlay = document.getElementById('recipe-modal-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'recipe-modal-overlay';
        overlay.style.cssText = `
            position:fixed;top:0;left:0;width:100%;height:100%;
            background:rgba(0,0,0,0.5);z-index:10000;display:flex;
            align-items:center;justify-content:center;padding:20px;
        `;
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });
        document.body.appendChild(overlay);
    }

    overlay.innerHTML = `<div style="
        background:#fff7e6;border-radius:18px;max-width:650px;width:100%;
        max-height:85vh;overflow-y:auto;padding:32px;position:relative;
        box-shadow:0 20px 60px rgba(0,0,0,0.25);font-family:Inter,sans-serif;
    "><p style="text-align:center;color:#6d4c41;">Loading recipe...</p></div>`;

    apiCall(`recipes.php?action=get&id=${recipeId}`).then(r => {
        const totalTime = (r.prep_time || 0) + (r.cook_time || 0);
        const ingredients = (r.ingredients || []);
        const instructions = (r.instructions || []);
        const tags = (r.tags || []);

        overlay.innerHTML = `<div style="
            background:#fff7e6;border-radius:18px;max-width:650px;width:100%;
            max-height:85vh;overflow-y:auto;padding:32px;position:relative;
            box-shadow:0 20px 60px rgba(0,0,0,0.25);font-family:Inter,sans-serif;
        ">
            <button onclick="document.getElementById('recipe-modal-overlay').remove()" style="
                position:absolute;top:16px;right:16px;background:none;border:none;
                font-size:1.4rem;cursor:pointer;color:#6d4c41;padding:4px 8px;
            ">✕</button>

            <h2 style="color:#3e2723;margin:0 0 8px 0;font-size:1.5rem;">${escapeHtml(r.title)}</h2>
            ${r.description ? `<p style="color:#5d4a3a;margin:0 0 16px 0;">${escapeHtml(r.description)}</p>` : ''}

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                ${totalTime ? `<span style="background:#e8f3d8;padding:6px 14px;border-radius:20px;font-size:0.85rem;color:#3e2723;font-weight:600;">⏱ ${totalTime} min</span>` : ''}
                ${r.estimated_cost ? `<span style="background:#fff3cd;padding:6px 14px;border-radius:20px;font-size:0.85rem;color:#3e2723;font-weight:600;">£${parseFloat(r.estimated_cost).toFixed(2)}</span>` : ''}
                ${r.difficulty ? `<span style="background:#f3e5f5;padding:6px 14px;border-radius:20px;font-size:0.85rem;color:#3e2723;font-weight:600;">${r.difficulty}</span>` : ''}
                ${r.servings ? `<span style="background:#e3f2fd;padding:6px 14px;border-radius:20px;font-size:0.85rem;color:#3e2723;font-weight:600;">👥 ${r.servings} servings</span>` : ''}
            </div>

            ${tags.length ? `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;">
                ${tags.map(t => `<span style="background:#c8e6c9;padding:3px 10px;border-radius:12px;font-size:0.75rem;color:#2e7d32;">${escapeHtml(t)}</span>`).join('')}
            </div>` : ''}

            <h3 style="color:#3e2723;margin:0 0 10px 0;font-size:1.1rem;">Ingredients</h3>
            <ul style="margin:0 0 24px 0;padding-left:20px;color:#4a3728;line-height:1.8;">
                ${ingredients.map(i => `<li>${escapeHtml(i)}</li>`).join('')}
            </ul>

            <h3 style="color:#3e2723;margin:0 0 10px 0;font-size:1.1rem;">Method</h3>
            <ol style="margin:0 0 24px 0;padding-left:20px;color:#4a3728;line-height:1.9;">
                ${instructions.map(s => `<li style="margin-bottom:8px;">${escapeHtml(s)}</li>`).join('')}
            </ol>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                ${r.id ? `<button id="modal-save-btn" data-recipe-id="${r.id}" style="
                    background:#7cb342;color:#fff;border:none;padding:10px 20px;
                    border-radius:10px;cursor:pointer;font-weight:600;font-size:0.9rem;
                ">${r.is_saved ? 'Saved' : 'Save Recipe'}</button>` : ''}
                <button onclick="document.getElementById('recipe-modal-overlay').remove()" style="
                    background:#e0e0e0;color:#3e2723;border:none;padding:10px 20px;
                    border-radius:10px;cursor:pointer;font-weight:600;font-size:0.9rem;
                ">Close</button>
            </div>
        </div>`;

        const saveBtn = document.getElementById('modal-save-btn');
        if (saveBtn && !r.is_saved) {
            saveBtn.addEventListener('click', async () => {
                try {
                    await apiCall(`recipes.php?action=save&recipe_id=${saveBtn.dataset.recipeId}`, { method: 'POST' });
                    saveBtn.textContent = 'Saved';
                    saveBtn.disabled = true;
                    showToast('Recipe saved!');
                } catch (err) {
                    showToast(err.error || 'Could not save', 'error');
                }
            });
        }
    }).catch(() => {
        overlay.innerHTML = `<div style="background:#fff7e6;border-radius:18px;padding:32px;text-align:center;">
            <p style="color:#e53935;">Failed to load recipe details.</p>
            <button onclick="document.getElementById('recipe-modal-overlay').remove()" style="
                background:#e0e0e0;border:none;padding:10px 20px;border-radius:10px;cursor:pointer;margin-top:12px;
            ">Close</button>
        </div>`;
    });
}

// ============================================
// HTML ESCAPE UTILITY
// ============================================

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ============================================
// PAGE ROUTER - Auto-init based on current page
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    const path = window.location.pathname;
    console.log('path is:', path);
    
    if (path.includes('login')) {
        initLoginPage();
    } else if (path.includes('dashboard')) {
        initDashboardPage();
    } else if (path.includes('recipes')) {
        initRecipesPage();
    } else if (path.includes('planner')) {
        initPlannerPage();
    } else if (path.includes('social')) {
        initSocialPage();
    } else if (path.includes('profile')) {
        initProfilePage();
    }
});
