# ByteRight - Smart Cooking for Students

ByteRight is a student-focused meal planning and recipe web application. It helps students find budget-friendly recipes, plan weekly meals, generate shopping lists, and share cooking experiences with friends.

## Features

- **Recipe Search** - Search by ingredients you have on hand, with optional dietary/time/cost filters. Uses the Spoonacular API with a local recipe database fallback.
- **Meal Planner** - Auto-generate a weekly meal plan based on your budget and dietary preferences.
- **Shopping List** - Automatically aggregated from your meal plan, grouped by category.
- **Social Feed** - Share posts about what you cooked, like and comment on friends' posts.
- **Friends** - Add friends by email, accept/decline requests, see what they are cooking.
- **Fridge Tracker** - Log ingredients you already have; they auto-populate the recipe search.
- **Profile & Preferences** - Set dietary preferences, allergies, budget, liked/disliked ingredients.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Backend | PHP 8.1+ |
| Database | MySQL 8.0 / MariaDB 10.6+ |
| Server | Apache (XAMPP recommended for local development) |
| External API | Spoonacular Food API (optional) |

## Prerequisites

Before you begin, ensure you have the following installed:

1. **XAMPP** (recommended) - Download from [apachefriends.org](https://www.apachefriends.org/)
   - Includes Apache, MySQL, and PHP bundled together
   - Alternatively, you can use any Apache + MySQL + PHP setup
2. **Git** - For cloning the repository
3. **Web browser** - Chrome, Firefox, Safari, or Edge (modern version)

## Installation

### Step 1: Clone the repository

```bash
cd /path/to/your/htdocs
# For XAMPP on macOS: /Applications/XAMPP/xamppfiles/htdocs/
# For XAMPP on Windows: C:\xampp\htdocs\
# For XAMPP on Linux: /opt/lampp/htdocs/

git clone https://github.com/divyanshjhajhria/Byte-Right.git
cd Byte-Right
```

### Step 2: Start XAMPP services

1. Open the **XAMPP Control Panel**
2. Start **Apache** (web server)
3. Start **MySQL** (database server)
4. Verify both are running (green status indicators)

### Step 3: Set up the database

**Option A: Run the setup script in your browser**

Open your browser and navigate to:
```
http://localhost/Byte-Right/backend/setup.php
```

This will automatically:
- Create the `byteright` database
- Create all required tables
- Seed the database with 30 starter recipes

**Option B: Run from the command line**

```bash
php backend/setup.php
```

**Optional: Seed demo data for presentation**

After running setup.php, you can populate the app with demo users, friendships, social posts, comments, likes, meal plans, and activity data:

```bash
php backend/seed_demo_data.php
```

This creates 8 demo users (password: `demo12345`) with realistic social interactions. Log in as any demo user, e.g. `sarah.mitchell@manchester.ac.uk` to see the full app experience.

**Option C: Manual import**

1. Open phpMyAdmin at `http://localhost/phpmyadmin`
2. Create a new database called `byteright`
3. Select the database, go to the **Import** tab
4. Upload `backend/schema.sql` and execute

### Step 4: Configure the application (optional)

**Spoonacular API Key (optional):**

The app works fully without an API key using the local recipe database. To enable online recipe search via Spoonacular:

1. Sign up for a free API key at [spoonacular.com/food-api](https://spoonacular.com/food-api)
2. Open `backend/config/database.php`
3. Add your key to the `SPOONACULAR_API_KEY` constant:
   ```php
   define('SPOONACULAR_API_KEY', 'your_api_key_here');
   ```

**Database credentials:**

If your MySQL uses a different username or password (not the default `root` with no password), update these values in `backend/config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'byteright');
define('DB_USER', 'root');       // Change if needed
define('DB_PASS', '');           // Change if needed
```

### Step 5: Launch the application

Open your browser and go to:
```
http://localhost/Byte-Right/frontend/byteright_login.html
```

1. Click **Sign Up** to create a new account
2. Fill in your name, email, and password
3. You will be automatically logged in and redirected to the dashboard

## Project Structure

```
Byte-Right/
├── frontend/                    # All frontend files
│   ├── byteright_login.html     # Login / registration page
│   ├── byteright_dashboard.html # Home dashboard
│   ├── byteright_recipes.html   # Recipe search page
│   ├── byteright_planner.html   # Weekly meal planner
│   ├── byteright_social.html    # Social feed
│   ├── byteright_profile.html   # User profile & settings
│   ├── common.css               # Shared styles (nav, variables, base)
│   ├── dashboard.css            # Dashboard-specific styles
│   ├── social.css               # Social feed styles
│   ├── app.js                   # Main frontend JavaScript (API calls, page logic)
│   ├── app.local.js             # Local dev overrides (not committed, see below)
│   └── logo.png                 # Application logo
│
├── backend/
│   ├── config/
│   │   └── database.php         # DB connection, session, CORS, helpers
│   ├── api/
│   │   ├── auth.php             # Login, register, logout, session check
│   │   ├── recipes.php          # Recipe search, save, random, detail
│   │   ├── mealplan.php         # Generate/view meal plans
│   │   ├── shopping.php         # Shopping list generation & management
│   │   ├── social.php           # Posts, likes, comments, image upload
│   │   ├── friends.php          # Friend requests, accept/decline, list
│   │   ├── fridge.php           # Fridge inventory management
│   │   └── profile.php          # User profile, stats, preferences
│   ├── uploads/                 # User-uploaded images (created automatically)
│   ├── schema.sql               # Full database schema + seed data
│   └── setup.php                # One-click database setup script
│
└── README.md                    # This file
```

## API Endpoints

All API endpoints are in `backend/api/`. They accept JSON or form data and return JSON responses.

| Endpoint | Actions |
|----------|---------|
| `auth.php` | `login`, `register`, `logout`, `status` |
| `recipes.php` | `search`, `get`, `save`, `unsave`, `saved`, `random` |
| `mealplan.php` | `generate`, `current`, `get`, `delete` |
| `shopping.php` | `generate`, `current`, `toggle`, `delete_item` |
| `social.php` | `feed`, `create`, `delete`, `like`, `unlike`, `comment`, `comments` |
| `friends.php` | `list`, `request`, `pending`, `accept`, `decline`, `remove` |
| `fridge.php` | `list`, `add`, `remove`, `clear` |
| `profile.php` | (default: get profile), `update`, `password`, `stats`, `activity` |

## Local Development

**Developer override file:**

Create a file at `frontend/app.local.js` to override the API base URL or add dev-only features. This file is gitignored and loaded before `app.js`:

```javascript
// Example: point to a different backend
// window.API_BASE_OVERRIDE = 'http://localhost:8080/api';
```

**CORS:**

The backend includes CORS headers for `localhost` origins, so you can serve the frontend from a different port during development if needed.

## Troubleshooting

| Issue | Solution |
|-------|---------|
| **Blank page after login** | Make sure both Apache and MySQL are running in XAMPP |
| **"Not authenticated" errors** | Check that cookies are enabled; the app uses PHP sessions |
| **Database connection error** | Verify MySQL is running and credentials in `database.php` are correct |
| **Recipe search returns no results** | Try broader ingredients (e.g., "chicken, rice"). The local DB has 30 seed recipes |
| **Image upload fails** | Check that `backend/uploads/` directory exists and is writable by Apache |
| **setup.php errors** | Ensure MySQL is running; if re-running, existing tables are safely skipped |

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Make your changes and commit
4. Push to your branch and open a Pull Request

## License

This project is developed as a university coursework project.
