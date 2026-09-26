<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/cycle.php';

// Placeholder data for Week 1 of a real cycle, adapted from the sample
// menu/shopping list in Docs/ (marked in PLAN.md as flavor, not ground
// truth — prices and quantities here are reasonable estimates, not the
// sample's exact numbers).

$pdo = get_db();

// Re-runnable: wipe in FK-safe order, then reseed.
foreach (['feedback', 'favorites', 'menu_entries', 'menu_cycles', 'meal_ingredients', 'meals', 'ingredients', 'budget_settings', 'users'] as $table) {
    $pdo->exec("DELETE FROM $table");
}

$pdo->beginTransaction();

// --- Users -------------------------------------------------------------
$insertUser = $pdo->prepare(
    'INSERT INTO users (name, email, pin_hash, role, theme, accent_hue) VALUES (:name, :email, :pin_hash, :role, :theme, :accent_hue)'
);
$userId = [];
foreach ([
    ['Herman', 'herman.ras.it@gmail.com', '2233', 'parent', 'light', 90],
    ['Freda', 'freda.ras@gmail.com', '2233', 'parent', 'light', 140],
    ['Alexander', 'alexander.ras.pc@gmail.com', '2233', 'child', 'dark', 210],
    ['Leanne', 'leanne.ras.pc@gmail.com', '2233', 'child', 'light', 330],
    ['Danie', 'danie.bekker@gmail.com', '2233', 'child', 'dark', 20],
] as [$name, $email, $pin, $role, $theme, $hue]) {
    $insertUser->execute([
        ':name' => $name,
        ':email' => $email,
        ':pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':role' => $role,
        ':theme' => $theme,
        ':accent_hue' => $hue,
    ]);
    $userId[$name] = (int) $pdo->lastInsertId();
}

// --- Budget --------------------------------------------------------------
$pdo->exec('INSERT INTO budget_settings (id, monthly_budget) VALUES (1, 2800.00)');

// --- Ingredient catalog ---------------------------------------------------
$insertIngredient = $pdo->prepare(
    'INSERT INTO ingredients (name, category, unit, estimated_price) VALUES (:name, :category, :unit, :price)'
);
$ingredientId = [];
$catalog = [
    ['Vetkoek flour mix', 'pantry', 'packet', 35.00],
    ['Beef mince', 'meat', 'kg', 120.00],
    ['Onion', 'produce', 'unit', 4.00],
    ['Tomato', 'produce', 'unit', 5.00],
    ['Curry powder', 'spices', 'packet', 25.00],
    ['Salt & pepper', 'spices', 'packet', 20.00],
    ['Cooking oil', 'pantry', 'litre', 40.00],
    ['Chicken fillets', 'meat', 'kg', 110.00],
    ['Mushrooms', 'produce', 'punnet', 30.00],
    ['Green pepper', 'produce', 'unit', 8.00],
    ['Cream', 'dairy', '250ml', 28.00],
    ['Chicken stock', 'pantry', 'cube', 3.00],
    ['Cornflour', 'pantry', 'packet', 22.00],
    ['Rice', 'pantry', 'kg', 28.00],
    ['Spaghetti', 'pantry', '500g', 22.00],
    ['Cheese worsies', 'meat', 'kg', 140.00],
    ['Tomato pasta sauce', 'pantry', 'jar', 32.00],
    ['Cheddar cheese', 'dairy', 'kg', 110.00],
    ['Garlic', 'produce', 'bulb', 6.00],
    ['Italian herbs', 'spices', 'packet', 18.00],
    ['Boerewors', 'meat', 'kg', 110.00],
    ['Bread rolls', 'bakery', 'pack of 6', 30.00],
    ['Lettuce', 'produce', 'head', 15.00],
    ['Tomato sauce', 'pantry', 'bottle', 25.00],
    ['Mustard', 'pantry', 'bottle', 28.00],
    ['Maize meal', 'pantry', 'kg', 22.00],
    ['Mixed vegetables', 'frozen', 'bag', 35.00],
    ['Chicken spice', 'spices', 'packet', 20.00],
    ['Gravy powder', 'pantry', 'packet', 18.00],
    ['Cocoa', 'pantry', '250g tin', 45.00],
    ['Sugar', 'pantry', 'kg', 25.00],
    ['Milk', 'dairy', 'litre', 20.00],
    ['Cinnamon', 'spices', 'packet', 15.00],
    ['Beef burger patties', 'meat', 'pack of 6', 85.00],
    ['Burger rolls', 'bakery', 'pack of 6', 28.00],
    ['Pineapple', 'pantry', 'tin', 22.00],
    ['Frozen chips', 'frozen', 'kg', 35.00],
    ['Steak (T-bone)', 'meat', 'kg', 120.00],
    ['Potatoes', 'produce', 'kg', 18.00],
    ['Mayonnaise', 'pantry', 'jar', 45.00],
    ['Eggs', 'dairy', 'egg', 3.50],
    ['Garlic bread', 'bakery', 'loaf', 35.00],
    ['Steak spice', 'spices', 'packet', 20.00],
    ['Butter', 'dairy', '250g', 35.00],
    ['Flour', 'pantry', 'kg', 20.00],
    ['Bread', 'bakery', 'loaf', 18.00],
    ['Ham', 'meat', '200g pack', 38.00],
];
foreach ($catalog as [$name, $category, $unit, $price]) {
    $insertIngredient->execute([':name' => $name, ':category' => $category, ':unit' => $unit, ':price' => $price]);
    $ingredientId[$name] = (int) $pdo->lastInsertId();
}

// --- Meals + their ingredients --------------------------------------------
$insertMeal = $pdo->prepare(
    'INSERT INTO meals (title, description, prep_steps, image) VALUES (:title, :description, :prep_steps, :image)'
);
$insertMealIngredient = $pdo->prepare(
    'INSERT INTO meal_ingredients (meal_id, ingredient_id, qty) VALUES (:meal_id, :ingredient_id, :qty)'
);

$mealId = [];
$meals = [
    'Vetkoek & Mince' => [
        'description' => 'Fried dough pockets filled with spiced beef mince.',
        'steps' => [
            'Make the vetkoek dough and let it prove, then deep fry until golden.',
            'Brown the mince with onion, tomato and curry powder.',
            'Split the vetkoek and spoon in the mince.',
        ],
        'ingredients' => [
            'Vetkoek flour mix' => 1,
            'Beef mince' => 1,
            'Onion' => 2,
            'Tomato' => 2,
            'Curry powder' => 1,
            'Salt & pepper' => 1,
            'Cooking oil' => 0.5,
        ],
    ],
    'Chicken à la King' => [
        'description' => 'Creamy chicken and mushroom sauce served over rice.',
        'steps' => [
            'Sauté the onion, mushrooms and green pepper.',
            'Add the chicken and cook through.',
            'Stir in cream, stock and cornflour to thicken; serve over rice.',
        ],
        'ingredients' => [
            'Chicken fillets' => 1,
            'Onion' => 1,
            'Mushrooms' => 1,
            'Green pepper' => 1,
            'Cream' => 1,
            'Chicken stock' => 2,
            'Cornflour' => 0.25,
            'Rice' => 0.5,
            'Salt & pepper' => 1,
        ],
    ],
    'Spaghetti & Cheese Worsies' => [
        'description' => 'Spaghetti with cheese worsies in a garlicky tomato sauce.',
        'steps' => [
            'Boil the spaghetti until al dente.',
            'Fry the cheese worsies, onion and garlic, then add the tomato sauce and herbs.',
            'Combine with the spaghetti and top with grated cheese.',
        ],
        'ingredients' => [
            'Spaghetti' => 1,
            'Cheese worsies' => 0.5,
            'Onion' => 1,
            'Tomato pasta sauce' => 1,
            'Cheddar cheese' => 0.2,
            'Garlic' => 1,
            'Italian herbs' => 1,
            'Salt & pepper' => 1,
        ],
    ],
    '2× Wors Broodjies' => [
        'description' => 'Grilled boerewors in a bread roll with all the trimmings.',
        'steps' => [
            'Grill the boerewors until cooked through.',
            'Split the rolls and add tomato, onion and lettuce.',
            'Add the wors and finish with tomato sauce and mustard.',
        ],
        'ingredients' => [
            'Boerewors' => 1,
            'Bread rolls' => 1,
            'Tomato' => 1,
            'Onion' => 1,
            'Lettuce' => 1,
            'Tomato sauce' => 1,
            'Mustard' => 1,
        ],
    ],
    'Chicken Fillets, Pap & Veg' => [
        'description' => 'Grilled chicken fillets with maize meal pap and mixed vegetables.',
        'steps' => [
            'Season the chicken with chicken spice and grill until cooked.',
            'Cook the pap according to packet instructions.',
            'Steam the mixed vegetables and serve with gravy.',
        ],
        'ingredients' => [
            'Chicken fillets' => 1,
            'Maize meal' => 0.5,
            'Mixed vegetables' => 1,
            'Onion' => 1,
            'Chicken spice' => 1,
            'Chicken stock' => 1,
            'Gravy powder' => 1,
            'Salt & pepper' => 1,
        ],
    ],
    'Chocolate Pap' => [
        'description' => 'Sweet maize meal porridge with cocoa and cinnamon.',
        'steps' => [
            'Cook the maize meal with milk until soft.',
            'Stir in cocoa, sugar and a dash of cinnamon.',
        ],
        'ingredients' => [
            'Maize meal' => 0.5,
            'Cocoa' => 0.1,
            'Sugar' => 0.1,
            'Milk' => 1,
            'Cinnamon' => 1,
        ],
    ],
    'Beef Burgers, Pineapple & Chips' => [
        'description' => 'Beef burgers topped with pineapple, served with chips.',
        'steps' => [
            'Grill the burger patties.',
            'Toast the rolls and build the burgers with lettuce, tomato, cheese and pineapple.',
            'Serve with oven-baked chips and tomato sauce.',
        ],
        'ingredients' => [
            'Beef burger patties' => 1,
            'Burger rolls' => 1,
            'Pineapple' => 1,
            'Lettuce' => 1,
            'Tomato' => 1,
            'Onion' => 1,
            'Cheddar cheese' => 0.2,
            'Tomato sauce' => 1,
            'Frozen chips' => 0.5,
        ],
    ],
    'T-Bone Steak, Potato Salad & Garlic Bread' => [
        'description' => "A weekend favourite — juicy grilled T-bone with a creamy homemade potato salad and buttery garlic bread on the side.",
        'steps' => [
            'Season the steaks with steak spice and let them rest at room temperature for 10 minutes.',
            'Boil the potatoes until just tender, then cool and dice.',
            'Mix the potatoes with mayonnaise, chopped egg and onion to make the potato salad.',
            'Grill or pan-fry the steaks to your preferred doneness.',
            'Butter the garlic bread with garlic and butter, then toast until golden.',
            'Serve the steak with the potato salad and garlic bread on the side.',
        ],
        'ingredients' => [
            'Steak (T-bone)' => 1.5,
            'Potatoes' => 1,
            'Mayonnaise' => 1,
            'Onion' => 2,
            'Eggs' => 6,
            'Garlic bread' => 1,
            'Steak spice' => 1,
            'Garlic' => 1,
            'Butter' => 1,
        ],
    ],
    'Pannekoek & Mince' => [
        'description' => 'Thin pancakes served alongside spiced beef mince.',
        'steps' => [
            'Whisk together flour, eggs, milk and oil to make the pancake batter, then fry thin pancakes.',
            'Brown the mince with onion, tomato and curry powder.',
            'Serve the pancakes with the mince.',
        ],
        'ingredients' => [
            'Flour' => 0.5,
            'Eggs' => 2,
            'Milk' => 0.5,
            'Cooking oil' => 0.25,
            'Beef mince' => 0.5,
            'Onion' => 1,
            'Tomato' => 1,
            'Curry powder' => 0.5,
            'Salt & pepper' => 1,
        ],
    ],
    'Toasties' => [
        'description' => 'Grilled cheese and ham toasted sandwiches.',
        'steps' => [
            'Butter the bread on the outside.',
            'Fill with cheese and ham.',
            'Grill in a toastie maker or pan until golden and the cheese has melted.',
        ],
        'ingredients' => [
            'Bread' => 1,
            'Cheddar cheese' => 0.2,
            'Ham' => 1,
            'Butter' => 0.5,
        ],
    ],
];

foreach ($meals as $title => $meal) {
    $insertMeal->execute([
        ':title' => $title,
        ':description' => $meal['description'],
        ':prep_steps' => json_encode($meal['steps'], JSON_UNESCAPED_UNICODE),
        ':image' => null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $mealId[$title] = $id;

    foreach ($meal['ingredients'] as $ingredientName => $qty) {
        $insertMealIngredient->execute([
            ':meal_id' => $id,
            ':ingredient_id' => $ingredientId[$ingredientName],
            ':qty' => $qty,
        ]);
    }
}

// --- Previous cycle (empty) -------------------------------------------------
// Contiguous with the cycle below, purely so "today" always falls inside a
// real cycle in this dev/demo dataset (whatever today happens to be while
// this is 2026) rather than a gap before the sample menu's dates start.
$prevCycle = generate_cycle(2026, 8);
$insertCycle = $pdo->prepare(
    'INSERT INTO menu_cycles (start_date, end_date, label) VALUES (:start, :end, :label)'
);
$insertCycle->execute([
    ':start' => $prevCycle['start'],
    ':end' => $prevCycle['end'],
    ':label' => cycle_label($prevCycle['start'], $prevCycle['end']),
]);

// --- Menu cycle + Week 1 entries ------------------------------------------
$cycle = generate_cycle(2026, 9);
$insertCycle = $pdo->prepare(
    'INSERT INTO menu_cycles (start_date, end_date, label) VALUES (:start, :end, :label)'
);
$insertCycle->execute([
    ':start' => $cycle['start'],
    ':end' => $cycle['end'],
    ':label' => cycle_label($cycle['start'], $cycle['end']),
]);
$cycleId = (int) $pdo->lastInsertId();

$insertEntry = $pdo->prepare(
    'INSERT INTO menu_entries (cycle_id, date, slot, meal_id, cooked, sort_order) VALUES (:cycle_id, :date, :slot, :meal_id, :cooked, 0)'
);

// Only Week 1 (the first 7 days of the cycle) is planned so far — the rest
// of the cycle is left empty, same as a family that hasn't planned ahead yet.
$week1Start = new DateTimeImmutable($cycle['start']);
$entries = [
    [0, 'dinner', 'Vetkoek & Mince', false],
    [1, 'dinner', 'Chicken à la King', false],
    [2, 'dinner', 'Spaghetti & Cheese Worsies', false],
    [3, 'dinner', '2× Wors Broodjies', false],
    [4, 'dinner', 'Chicken Fillets, Pap & Veg', false],
    [5, 'breakfast', 'Chocolate Pap', false],
    [5, 'lunch', 'Beef Burgers, Pineapple & Chips', false],
    [5, 'dinner', 'T-Bone Steak, Potato Salad & Garlic Bread', true],
    [6, 'breakfast', 'Chocolate Pap', false],
    [6, 'lunch', 'Pannekoek & Mince', false],
    [6, 'dinner', 'Toasties', false],
];
foreach ($entries as [$dayOffset, $slot, $title, $cooked]) {
    $insertEntry->execute([
        ':cycle_id' => $cycleId,
        ':date' => $week1Start->modify("+{$dayOffset} days")->format('Y-m-d'),
        ':slot' => $slot,
        ':meal_id' => $mealId[$title],
        ':cooked' => $cooked ? 1 : 0,
    ]);
}

// --- Favorites -------------------------------------------------------------
$pdo->prepare('INSERT INTO favorites (user_id, meal_id) VALUES (:user_id, :meal_id)')->execute([
    ':user_id' => $userId['Alexander'],
    ':meal_id' => $mealId['Beef Burgers, Pineapple & Chips'],
]);
$pdo->prepare('INSERT INTO favorites (user_id, meal_id) VALUES (:user_id, :meal_id)')->execute([
    ':user_id' => $userId['Leanne'],
    ':meal_id' => $mealId['Chocolate Pap'],
]);

// --- Feedback ---------------------------------------------------------------
$insertFeedback = $pdo->prepare(
    'INSERT INTO feedback (user_id, meal_name, note, status) VALUES (:user_id, :meal_name, :note, :status)'
);
$insertFeedback->execute([
    ':user_id' => $userId['Herman'],
    ':meal_name' => 'Bobotie',
    ':note' => '',
    ':status' => 'added',
]);
$insertFeedback->execute([
    ':user_id' => $userId['Alexander'],
    ':meal_name' => 'Butter Chicken',
    ':note' => "We had this at a friend's house, it was so good!",
    ':status' => 'pending',
]);
$insertFeedback->execute([
    ':user_id' => $userId['Leanne'],
    ':meal_name' => 'Malva Pudding',
    ':note' => 'Dessert idea for a Sunday lunch.',
    ':status' => 'pending',
]);

$pdo->commit();

echo "Seeded {$cycle['start']} to {$cycle['end']} (" . cycle_label($cycle['start'], $cycle['end']) . "), "
    . count($catalog) . " ingredients, " . count($meals) . " meals, " . count($entries) . " menu entries.\n";
