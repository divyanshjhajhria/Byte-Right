# ByteRight - Smart Cooking for Students

ByteRight is a student-focused meal planning and recipe web application. It helps students discover budget-friendly recipes, save favourites, generate meal plans, build shopping lists, and share cooking updates with friends.

## Core Features

- **Recipe Search** - Search by ingredients, dietary needs, time, and budget. Uses the Spoonacular API when configured, with a local recipe database as fallback.
- **Saved Recipes** - Save favourite recipes and quickly revisit them from the dashboard.
- **Meal Planner** - Generate a weekly meal plan based on budget and preferences.
- **Shopping List** - Build a shopping list automatically from a meal plan.
- **Social Feed** - Create posts, like and comment on friends' cooking updates.
- **Friends** - Send and accept friend requests.
- **Fridge Tracker** - Store ingredients you already have.
- **Profile & Preferences** - Manage dietary preferences, allergies, budget, and cooking settings.

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Backend | PHP 8.1+ |
| Database | MySQL 8.0 / MariaDB 10.6+ |
| Server | Apache (XAMPP recommended) |
| Optional API | Spoonacular Food API |

## Prerequisites

Before running the project, make sure you have:

1. **XAMPP** or another Apache + PHP + MySQL setup
2. **Git** if you want to clone the repository
3. A modern browser such as Chrome, Edge, Firefox, or Safari

## Installation

### 1. Place the project inside htdocs

```bash
cd /path/to/htdocs
# macOS XAMPP: /Applications/XAMPP/xamppfiles/htdocs/
# Windows XAMPP: C:\xampp\htdocs\
# Linux XAMPP: /opt/lampp/htdocs/

### 2. Start Apache and MySQL

Open XAMPP and start:

- **Apache**
- **MySQL**

### 3. Run the setup script

Open this in your browser:

```text
http://localhost/Byte-Right/backend/setup.php
```

Or run it from the terminal:

```bash
php backend/setup.php
```

### What `setup.php` does now

The current setup script is designed to be **safe to re-run**.

It will:

- create the `byteright` database if it does not already exist
- preserve existing users and user-generated content tables
- rebuild static catalog tables such as `recipes`, `dietary_preferences`, and `user_dietary_preferences`
- repair important missing columns such as:
  - `recipes.estimated_cost`
  - `recipes.image_url`
  - `recipes.popularity_score`
  - other newer fields used by the app
- create a default test login if it does not already exist

### Default test login created by `setup.php`

- **Email:** `divyansh.jhajhria0@gmail.com`
- **Password:** `12345678`

## Demo seeding

If you want presentation/demo data such as recipe images, demo users, friendships, social posts, likes, comments, activity, and sample meal-plan-related content, run:

```bash
php backend/seed_demo_data.php
```

Or open:

```text
http://localhost/Byte-Right/backend/seed_demo_data.php
```

### Important note about recipe images

`setup.php` builds the database and recipes, but homepage recipe images are populated by `seed_demo_data.php`.

So if the dashboard loads recipes but image cards still look empty, run `seed_demo_data.php` once.

## Launch the app

Open:

```text
http://localhost/Byte-Right/frontend/byteright_login.html
```

You can either:

- create a new account from the sign-up form, or
- log in with the default test account above

## Project Structure

```text
Byte-Right/
├── backend/
│   ├── api/
│   │   ├── auth.php
│   │   ├── friends.php
│   │   ├── fridge.php
│   │   ├── mealplan.php
│   │   ├── profile.php
│   │   ├── recipes.php
│   │   ├── shopping.php
│   │   └── social.php
│   ├── config/
│   │   └── database.php
│   ├── uploads/
│   ├── schema.sql
│   ├── seed_demo_data.php
│   └── setup.php
├── frontend/
│   ├── app.js
│   ├── app.local.example.js
│   ├── byteright_dashboard.html
│   ├── byteright_login.html
│   ├── byteright_planner.html
│   ├── byteright_profile.html
│   ├── byteright_recipes.html
│   ├── byteright_social.html
│   ├── common.css
│   ├── dashboard.css
│   ├── homepage.css
│   ├── login.css
│   ├── planner.css
│   ├── profile.css
│   ├── recipes.css
│   ├── social.css
│   ├── index.html
│   ├── logo.png
│   └── viral_pic.jpg
└── README.md
```

## API Endpoints

All backend endpoints are inside `backend/api/` and return JSON.

| Endpoint | Actions |
|---|---|
| `auth.php` | `register`, `login`, `logout`, `status` |
| `recipes.php` | `search`, `get`, `save`, `unsave`, `saved`, `random` |
| `mealplan.php` | `generate`, `current`, `get`, `delete` |
| `shopping.php` | `generate`, `current`, `toggle`, `delete_item` |
| `social.php` | `feed`, `create`, `delete`, `like`, `unlike`, `comment`, `comments` |
| `friends.php` | `list`, `request`, `pending`, `accept`, `decline`, `remove` |
| `fridge.php` | `list`, `add`, `remove`, `clear` |
| `profile.php` | default profile fetch, `update`, `password`, `stats`, `activity` |

## Spoonacular API Setup (Optional)

The app works without Spoonacular because it ships with a local recipe database.

To enable Spoonacular:

1. Get an API key from Spoonacular
2. Open `backend/config/database.php`
3. Update the API key constant there

If you do not add a key, local recipes still work.

## Troubleshooting

| Issue | Fix |
|---|---|
| Setup runs but images are missing | Run `seed_demo_data.php` |
| Meal plan generation errors mention `estimated_cost` | Re-run `setup.php` with the current project files |
| Login fails for the default account | Make sure you are using the updated `setup.php`, then re-run it |
| Database connection error | Check MySQL is running and verify credentials in `backend/config/database.php` |
| Image upload fails | Ensure `backend/uploads/` exists and is writable |


## Important Instruction

Change your folder name to Byte-Right for correct functionality as the unzipped folder name occurs as Z13_ByteRight which was not the intended development folder name. **Please keep this in mind**



