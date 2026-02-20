-- ByteRight Database Schema
-- Run this file to set up the MySQL database

CREATE DATABASE IF NOT EXISTS byteright;
USE byteright;

-- ============================================
-- USERS
-- ============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    university VARCHAR(150) DEFAULT NULL,
    avatar_path VARCHAR(255) DEFAULT NULL,
    weekly_budget DECIMAL(6,2) DEFAULT 30.00,
    cooking_time_pref ENUM('under15', 'under30', 'under60', 'any') DEFAULT 'under30',
    meal_plan_pref ENUM('balanced', 'high_protein', 'low_carb', 'budget') DEFAULT 'balanced',
    allergies TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================
-- DIETARY PREFERENCES (many-to-many)
-- ============================================
CREATE TABLE dietary_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);

INSERT INTO dietary_preferences (name) VALUES
('Vegetarian'), ('Vegan'), ('Gluten-Free'), ('Dairy-Free'), ('Halal'), ('Kosher');

CREATE TABLE user_dietary_preferences (
    user_id INT NOT NULL,
    preference_id INT NOT NULL,
    PRIMARY KEY (user_id, preference_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (preference_id) REFERENCES dietary_preferences(id) ON DELETE CASCADE
);

-- ============================================
-- RECIPES (local fallback library)
-- ============================================
CREATE TABLE recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    ingredients JSON NOT NULL,
    instructions JSON NOT NULL,
    prep_time INT DEFAULT 0,          -- minutes
    cook_time INT DEFAULT 0,          -- minutes
    servings INT DEFAULT 2,
    estimated_cost DECIMAL(6,2) DEFAULT 0.00,
    difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'easy',
    image_url VARCHAR(500) DEFAULT NULL,
    tags JSON DEFAULT NULL,           -- e.g. ["vegetarian","quick","budget"]
    source ENUM('api', 'local', 'user') DEFAULT 'local',
    spoonacular_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_recipes_spoonacular ON recipes(spoonacular_id);

-- ============================================
-- SAVED RECIPES (user bookmarks)
-- ============================================
CREATE TABLE saved_recipes (
    user_id INT NOT NULL,
    recipe_id INT NOT NULL,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, recipe_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
);

-- ============================================
-- MEAL PLANS
-- ============================================
CREATE TABLE meal_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    week_start DATE NOT NULL,
    budget_target DECIMAL(6,2) DEFAULT NULL,
    total_estimated_cost DECIMAL(6,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_week (user_id, week_start)
);

CREATE TABLE meal_plan_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meal_plan_id INT NOT NULL,
    day_of_week TINYINT NOT NULL,     -- 0=Mon, 6=Sun
    meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
    recipe_id INT DEFAULT NULL,
    custom_meal_name VARCHAR(200) DEFAULT NULL,
    FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
);

-- ============================================
-- SHOPPING LISTS
-- ============================================
CREATE TABLE shopping_lists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    meal_plan_id INT DEFAULT NULL,
    name VARCHAR(100) DEFAULT 'My Shopping List',
    estimated_total DECIMAL(6,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE SET NULL
);

CREATE TABLE shopping_list_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shopping_list_id INT NOT NULL,
    ingredient_name VARCHAR(150) NOT NULL,
    quantity VARCHAR(50) DEFAULT NULL,
    unit VARCHAR(30) DEFAULT NULL,
    category ENUM('fresh_produce', 'store_cupboard', 'fridge_freezer', 'other') DEFAULT 'other',
    estimated_price DECIMAL(6,2) DEFAULT NULL,
    checked TINYINT(1) DEFAULT 0,
    FOREIGN KEY (shopping_list_id) REFERENCES shopping_lists(id) ON DELETE CASCADE
);

-- ============================================
-- SOCIAL POSTS
-- ============================================
CREATE TABLE posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    recipe_id INT DEFAULT NULL,
    likes_count INT DEFAULT 0,
    comments_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL
);

CREATE TABLE post_likes (
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE post_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- FRIENDS
-- ============================================
CREATE TABLE friendships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requester_id INT NOT NULL,
    addressee_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_friendship (requester_id, addressee_id)
);

-- ============================================
-- USER ACTIVITY LOG
-- ============================================
CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action_type ENUM('recipe_saved', 'recipe_cooked', 'post_created', 'plan_created', 'friend_added') NOT NULL,
    reference_id INT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================
-- SEED DATA: Fallback Recipe Library (30 diverse recipes)
-- ============================================
INSERT INTO recipes (title, description, ingredients, instructions, prep_time, cook_time, servings, estimated_cost, difficulty, tags, source) VALUES

-- BREAKFAST
('Classic Scrambled Eggs on Toast', 'Creamy scrambled eggs on buttered toast - a student staple.',
 '["3 eggs","2 slices bread","1 tbsp butter","salt","pepper","splash of milk"]',
 '["Crack eggs into bowl, add milk, salt and pepper, whisk.","Melt butter in pan over low heat.","Pour in eggs, stir gently with spatula.","Remove when just set but still creamy.","Toast bread, butter and serve eggs on top."]',
 2, 5, 1, 1.20, 'easy', '["breakfast","quick","budget"]', 'local'),

('Overnight Oats', 'Prep the night before for a grab-and-go breakfast.',
 '["50g rolled oats","150ml milk","1 tbsp honey","handful of berries","1 tbsp chia seeds"]',
 '["Combine oats, milk, chia seeds and honey in a jar.","Stir well, seal and refrigerate overnight.","In the morning top with berries.","Eat cold or microwave for 1 minute."]',
 5, 0, 1, 0.90, 'easy', '["breakfast","meal_prep","budget","vegetarian"]', 'local'),

('Banana Pancakes', 'Two-ingredient pancakes that are naturally sweet.',
 '["2 bananas","2 eggs","pinch of cinnamon","butter for frying","maple syrup to serve"]',
 '["Mash bananas until smooth.","Beat in eggs and cinnamon.","Heat butter in pan over medium heat.","Pour small rounds of batter, cook 2 min each side.","Serve with maple syrup."]',
 5, 10, 2, 1.50, 'easy', '["breakfast","gluten_free"]', 'local'),

-- QUICK LUNCHES
('Tomato Soup', 'Hearty homemade tomato soup from canned tomatoes.',
 '["1 can chopped tomatoes","1 onion","2 cloves garlic","500ml vegetable stock","1 tbsp olive oil","salt","pepper","dried basil"]',
 '["Dice onion and garlic, fry in olive oil until soft.","Add canned tomatoes and stock.","Season with salt, pepper and basil.","Simmer 15 minutes.","Blend until smooth, serve with bread."]',
 5, 20, 2, 1.80, 'easy', '["lunch","budget","vegan","vegetarian"]', 'local'),

('Cheese Toastie', 'The ultimate comfort food - crispy golden cheese toastie.',
 '["2 slices bread","50g cheddar cheese","1 tbsp butter","optional: ham slice","optional: tomato slice"]',
 '["Butter outsides of bread slices.","Layer cheese between bread, buttered sides out.","Cook in pan over medium heat 3 min each side.","Press down gently until golden and cheese melts.","Slice diagonally and serve."]',
 2, 6, 1, 1.00, 'easy', '["lunch","quick","budget","vegetarian"]', 'local'),

('Egg Fried Rice', 'Restaurant-style egg fried rice using leftover rice.',
 '["250g cooked rice (cold)","2 eggs","2 tbsp soy sauce","1 tbsp sesame oil","100g frozen peas","2 spring onions","1 clove garlic"]',
 '["Heat sesame oil in wok over high heat.","Scramble eggs, set aside.","Fry garlic and peas 2 minutes.","Add cold rice, stir-fry 3 minutes.","Add soy sauce and scrambled eggs, toss.","Top with sliced spring onions."]',
 5, 10, 2, 1.60, 'easy', '["lunch","dinner","quick","budget"]', 'local'),

('Quesadilla', 'Crispy cheesy quesadillas with simple fillings.',
 '["2 flour tortillas","80g grated cheese","1 pepper diced","handful of sweetcorn","1 tsp paprika","sour cream to serve"]',
 '["Mix cheese, pepper, sweetcorn and paprika.","Spread filling on one tortilla, top with second.","Dry-fry in pan 3 min each side until golden.","Cut into wedges.","Serve with sour cream."]',
 5, 6, 1, 1.80, 'easy', '["lunch","quick","vegetarian"]', 'local'),

-- DINNERS
('Spaghetti Bolognese', 'A classic family favourite, budget-friendly and filling.',
 '["200g spaghetti","250g minced beef","1 onion","2 cloves garlic","1 can chopped tomatoes","1 tbsp tomato puree","1 carrot","dried oregano","salt","pepper","olive oil"]',
 '["Cook spaghetti per packet instructions.","Fry diced onion, garlic and grated carrot in oil.","Add mince, brown well.","Stir in tomatoes, puree and oregano.","Simmer 20 mins until thick.","Serve sauce over drained spaghetti."]',
 10, 30, 3, 3.50, 'easy', '["dinner","budget","meal_prep"]', 'local'),

('Chicken Stir-Fry', 'Quick and healthy stir-fry with whatever veg you have.',
 '["2 chicken breasts","1 pepper","1 courgette","100g mushrooms","2 tbsp soy sauce","1 tbsp honey","1 clove garlic","1 tsp ginger","rice to serve"]',
 '["Slice chicken into strips, season.","Stir-fry chicken in hot oil 5 mins until cooked.","Add sliced veg, cook 3 mins.","Mix soy sauce, honey, garlic and ginger, pour over.","Toss 1 minute.","Serve over steamed rice."]',
 10, 12, 2, 4.00, 'easy', '["dinner","quick","healthy"]', 'local'),

('Vegetable Curry', 'Creamy coconut curry packed with vegetables.',
 '["1 onion","2 cloves garlic","1 can coconut milk","2 tbsp curry paste","1 sweet potato cubed","100g spinach","1 can chickpeas","1 tbsp oil","rice to serve"]',
 '["Fry onion and garlic in oil.","Add curry paste, fry 1 minute.","Add sweet potato and coconut milk.","Simmer 15 mins until potato is soft.","Stir in chickpeas and spinach, cook 3 mins.","Serve with rice."]',
 10, 20, 3, 3.20, 'medium', '["dinner","vegan","vegetarian","healthy","meal_prep"]', 'local'),

('Pasta Carbonara', 'Rich and creamy carbonara - no cream needed.',
 '["200g spaghetti","100g bacon lardons","2 eggs","50g parmesan","2 cloves garlic","black pepper"]',
 '["Cook spaghetti in salted water.","Fry lardons until crispy, add garlic.","Whisk eggs and parmesan together.","Drain pasta, reserve 50ml pasta water.","Toss hot pasta with lardons off heat.","Quickly stir in egg mixture, add pasta water to loosen."]',
 5, 15, 2, 3.00, 'medium', '["dinner","quick"]', 'local'),

('Chilli Con Carne', 'Warming spiced chilli, great for meal prep.',
 '["250g minced beef","1 onion","2 cloves garlic","1 can kidney beans","1 can chopped tomatoes","1 tbsp tomato puree","1 tsp cumin","1 tsp chilli powder","rice to serve"]',
 '["Brown mince in pan, drain fat.","Fry onion and garlic until soft.","Add spices, stir 1 min.","Add tomatoes, puree and drained beans.","Simmer 25 minutes.","Serve with rice and optional toppings."]',
 10, 30, 3, 3.50, 'easy', '["dinner","budget","meal_prep"]', 'local'),

('Bean Burrito Bowl', 'Tex-Mex bowl loaded with beans, rice and toppings.',
 '["1 can black beans","200g rice","1 avocado","1 tomato","50g cheese","1 lime","1 tsp cumin","lettuce","sour cream"]',
 '["Cook rice with a pinch of cumin.","Heat and season black beans.","Dice tomato and avocado, squeeze lime over.","Shred lettuce.","Assemble bowl: rice, beans, veg, cheese, sour cream."]',
 10, 15, 2, 2.80, 'easy', '["dinner","lunch","vegetarian","budget"]', 'local'),

('Shepherd''s Pie', 'Comfort food at its finest with crispy mashed potato top.',
 '["300g minced lamb","3 potatoes","1 onion","1 carrot","100g frozen peas","1 tbsp tomato puree","200ml gravy","butter","milk"]',
 '["Boil potatoes until soft, mash with butter and milk.","Brown mince, add diced onion and carrot.","Add peas, puree and gravy, simmer 10 mins.","Transfer to oven dish, top with mash.","Bake 200°C for 20 mins until golden."]',
 15, 35, 3, 4.00, 'medium', '["dinner","comfort","meal_prep"]', 'local'),

('Thai Green Curry', 'Aromatic Thai curry ready in under 30 minutes.',
 '["2 chicken breasts","1 can coconut milk","2 tbsp green curry paste","100g green beans","1 courgette","1 tbsp fish sauce","1 tsp sugar","basil leaves","rice to serve"]',
 '["Slice chicken, stir-fry 4 minutes.","Add curry paste, fry 1 min.","Pour in coconut milk, bring to simmer.","Add sliced veg, cook 8 minutes.","Season with fish sauce and sugar.","Serve over rice with basil."]',
 10, 15, 2, 4.50, 'medium', '["dinner","quick"]', 'local'),

-- BUDGET MEALS
('Jacket Potato with Beans', 'Ultimate cheap and cheerful meal.',
 '["2 large baking potatoes","1 can baked beans","50g grated cheese","butter","salt","pepper"]',
 '["Prick potatoes, microwave 10 mins or bake 200°C for 1 hour.","Heat beans in pan.","Split potatoes, add butter.","Top with beans and cheese."]',
 2, 12, 2, 1.50, 'easy', '["dinner","lunch","budget","vegetarian"]', 'local'),

('Lentil Daal', 'Hearty and cheap lentil curry - student superfood.',
 '["200g red lentils","1 onion","2 cloves garlic","1 can chopped tomatoes","1 tsp turmeric","1 tsp cumin","1 tsp garam masala","400ml water","1 tbsp oil","naan to serve"]',
 '["Rinse lentils well.","Fry onion and garlic in oil.","Add spices, fry 1 minute.","Add lentils, tomatoes and water.","Simmer 20 mins, stirring occasionally until thick.","Serve with naan or rice."]',
 5, 25, 3, 1.80, 'easy', '["dinner","budget","vegan","vegetarian","healthy"]', 'local'),

('Tuna Pasta Bake', 'Cheap, filling and feeds a crowd.',
 '["250g pasta","1 can tuna","1 can sweetcorn","200ml creme fraiche","100g grated cheese","1 tsp mustard","salt","pepper"]',
 '["Cook pasta, drain.","Mix creme fraiche, mustard, drained tuna and sweetcorn.","Combine with pasta.","Transfer to oven dish, top with cheese.","Bake 200°C for 15 mins until golden."]',
 5, 20, 3, 3.00, 'easy', '["dinner","budget","meal_prep"]', 'local'),

('Omelette', 'Quick omelette with whatever fillings you have.',
 '["3 eggs","30g cheese","handful of mushrooms","1 tomato","salt","pepper","butter"]',
 '["Beat eggs with salt and pepper.","Melt butter in pan over medium heat.","Pour in eggs, swirl to cover base.","When edges set, add fillings to one half.","Fold omelette over, slide onto plate."]',
 3, 5, 1, 1.30, 'easy', '["breakfast","lunch","dinner","quick","budget","gluten_free"]', 'local'),

-- HEALTHY
('Grilled Salmon with Veg', 'Simple and nutritious salmon dinner.',
 '["2 salmon fillets","200g broccoli","200g new potatoes","1 lemon","1 tbsp olive oil","salt","pepper","dill"]',
 '["Boil potatoes 15 mins until tender.","Season salmon with oil, lemon, salt, pepper, dill.","Grill salmon 4 mins each side.","Steam broccoli 4 minutes.","Serve together with lemon wedge."]',
 5, 20, 2, 5.00, 'easy', '["dinner","healthy","gluten_free"]', 'local'),

('Greek Salad', 'Fresh and vibrant Mediterranean salad.',
 '["1 cucumber","3 tomatoes","1 red onion","100g feta cheese","olives","2 tbsp olive oil","1 tbsp red wine vinegar","dried oregano"]',
 '["Chop cucumber, tomatoes and onion into chunks.","Add olives and crumbled feta.","Whisk olive oil, vinegar and oregano.","Drizzle dressing over salad, toss gently."]',
 10, 0, 2, 2.50, 'easy', '["lunch","healthy","vegetarian","gluten_free","quick"]', 'local'),

('Chicken Wrap', 'Healthy grilled chicken wrap with fresh veg.',
 '["1 chicken breast","1 large tortilla","lettuce","1 tomato","cucumber","2 tbsp yoghurt","1 tsp paprika","salt"]',
 '["Season chicken with paprika and salt.","Grill or pan-fry 6 mins each side until cooked.","Slice chicken.","Lay tortilla flat, spread yoghurt.","Add lettuce, tomato, cucumber and chicken.","Roll tightly and cut in half."]',
 5, 12, 1, 2.50, 'easy', '["lunch","healthy","quick"]', 'local'),

-- SNACKS & SIDES
('Hummus', 'Creamy homemade hummus - way better than shop bought.',
 '["1 can chickpeas","2 tbsp tahini","1 lemon juiced","1 clove garlic","2 tbsp olive oil","pinch of cumin","salt","paprika to garnish"]',
 '["Drain chickpeas, reserve liquid.","Blend chickpeas, tahini, lemon, garlic and oil.","Add 2-3 tbsp reserved liquid until smooth.","Season with cumin and salt.","Serve drizzled with oil and paprika."]',
 10, 0, 4, 1.20, 'easy', '["snack","vegan","vegetarian","healthy","gluten_free"]', 'local'),

('Garlic Bread', 'Crispy homemade garlic bread.',
 '["1 baguette","50g butter softened","3 cloves garlic minced","1 tbsp parsley chopped","pinch of salt"]',
 '["Mix butter, garlic, parsley and salt.","Slice baguette at angles without cutting through.","Spread garlic butter between slices.","Wrap in foil, bake 200°C for 10 mins.","Open foil, bake 5 more mins until crispy."]',
 5, 15, 4, 1.00, 'easy', '["side","vegetarian","quick"]', 'local'),

-- MORE DIVERSE MAINS
('Mushroom Risotto', 'Creamy Italian risotto with earthy mushrooms.',
 '["200g arborio rice","200g mushrooms","1 onion","2 cloves garlic","750ml vegetable stock","50ml white wine","30g parmesan","1 tbsp butter","1 tbsp olive oil"]',
 '["Heat stock and keep warm.","Fry onion and garlic in oil, add sliced mushrooms.","Add rice, stir 1 min.","Add wine, stir until absorbed.","Add stock one ladle at a time, stirring between.","Finish with butter and parmesan."]',
 5, 25, 2, 3.00, 'medium', '["dinner","vegetarian","comfort"]', 'local'),

('Fish Fingers with Chips', 'Homemade fish fingers way better than frozen.',
 '["2 white fish fillets","50g breadcrumbs","30g flour","1 egg","3 potatoes","oil for frying","salt","pepper","lemon"]',
 '["Cut potatoes into chips, toss in oil, bake 200°C 30 mins.","Cut fish into fingers.","Coat in flour, dip in beaten egg, roll in breadcrumbs.","Shallow fry 3 mins each side until golden.","Serve with chips and lemon."]',
 10, 30, 2, 3.50, 'easy', '["dinner","comfort","budget"]', 'local'),

('Stuffed Peppers', 'Colourful peppers filled with rice and veg.',
 '["4 bell peppers","200g cooked rice","1 can black beans","100g sweetcorn","1 tsp cumin","50g cheese","salsa to serve"]',
 '["Cut tops off peppers, remove seeds.","Mix rice, beans, sweetcorn, cumin and half the cheese.","Fill peppers with mixture.","Top with remaining cheese.","Bake 200°C for 25 minutes.","Serve with salsa."]',
 10, 25, 2, 3.00, 'easy', '["dinner","vegetarian","healthy","meal_prep"]', 'local'),

('Chicken Fajitas', 'Sizzling fajitas with peppers and onions.',
 '["2 chicken breasts","2 peppers","1 onion","1 tbsp fajita seasoning","1 tbsp oil","4 tortillas","sour cream","salsa","guacamole"]',
 '["Slice chicken, peppers and onion.","Toss chicken with seasoning and oil.","Stir-fry chicken 5 mins.","Add peppers and onion, cook 4 mins.","Warm tortillas.","Serve with toppings."]',
 10, 10, 2, 4.00, 'easy', '["dinner","quick"]', 'local'),

('Ramen Noodle Soup', 'Warming noodle soup with a rich broth.',
 '["2 packs instant noodles (discard seasoning)","600ml chicken stock","1 tbsp soy sauce","1 tsp sesame oil","2 eggs","100g mushrooms","spring onions","chilli flakes"]',
 '["Bring stock to boil with soy sauce and sesame oil.","Add sliced mushrooms, simmer 3 mins.","Cook noodles in broth 3 minutes.","Soft-boil eggs separately (6 mins), halve.","Serve noodles in broth topped with egg, spring onion, chilli."]',
 5, 12, 2, 2.00, 'easy', '["dinner","lunch","quick","budget","comfort"]', 'local'),

('Shakshuka', 'North African spiced tomato and egg dish.',
 '["1 can chopped tomatoes","4 eggs","1 onion","1 pepper","2 cloves garlic","1 tsp cumin","1 tsp paprika","pinch of chilli","fresh coriander","bread to serve"]',
 '["Fry onion, pepper and garlic until soft.","Add spices, stir 1 minute.","Pour in tomatoes, simmer 10 mins until thick.","Make 4 wells, crack in eggs.","Cover, cook 5 mins until whites set.","Garnish with coriander, serve with bread."]',
 5, 20, 2, 2.50, 'easy', '["breakfast","dinner","budget","healthy"]', 'local');
