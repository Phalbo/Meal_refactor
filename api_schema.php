<?php

function initSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA cache_size = -8000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    // ── Tabelle ────────────────────────────────────────────────────────────────

    $pdo->exec("CREATE TABLE IF NOT EXISTS family_profiles (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        name           TEXT NOT NULL,
        type           TEXT NOT NULL DEFAULT 'adult' CHECK(type IN ('adult','child')),
        portion_weight REAL NOT NULL DEFAULT 1.0,
        avatar_emoji   TEXT NOT NULL DEFAULT '\u{1F464}',
        intolleranze   TEXT NOT NULL DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meal_categories (
        id   INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meals (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        emoji         TEXT NOT NULL DEFAULT '\u{1F37D}️',
        category_id   INTEGER REFERENCES meal_categories(id),
        cal_per_adult INTEGER NOT NULL DEFAULT 0,
        notes         TEXT,
        is_system     INTEGER NOT NULL DEFAULT 0,
        is_favorite   INTEGER NOT NULL DEFAULT 0,
        use_count     INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS meal_ingredients (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        meal_id           INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
        name              TEXT NOT NULL,
        quantity          REAL,
        unit              TEXT,
        price_est         REAL NOT NULL DEFAULT 0,
        intolerance_flags TEXT NOT NULL DEFAULT '',
        zone              TEXT NOT NULL DEFAULT 'scaffali'
    )");

    // Catalogo nutrizionale degli alimenti — zone valide: ortofrutta, pane,
    // macelleria, pesce, latticini, scaffali, bevande, surgelati, casalinghi, altro
    $pdo->exec("CREATE TABLE IF NOT EXISTS nutrition_db (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        name         TEXT NOT NULL UNIQUE,
        kcal_100g    REAL NOT NULL DEFAULT 0,
        zone         TEXT NOT NULL DEFAULT 'scaffali',
        price_est    REAL NOT NULL DEFAULT 0,
        aliases      TEXT NOT NULL DEFAULT '[]',
        unit_weights TEXT NOT NULL DEFAULT '{}'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schedule (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        week_start        TEXT NOT NULL,
        day_index         INTEGER NOT NULL CHECK(day_index BETWEEN 0 AND 6),
        slot              TEXT NOT NULL CHECK(slot IN ('colazione','pranzo','cena')),
        meal_id           INTEGER REFERENCES meals(id) ON DELETE SET NULL,
        slot_kids         TEXT    DEFAULT NULL,
        portions_override REAL    DEFAULT NULL,
        exception_note    TEXT,
        is_exception      INTEGER NOT NULL DEFAULT 0,
        UNIQUE(week_start, day_index, slot)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shopping_items (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        week_start      TEXT NOT NULL,
        ingredient_name TEXT NOT NULL,
        quantity        REAL,
        unit            TEXT,
        price_est       REAL NOT NULL DEFAULT 0,
        price_actual    REAL,
        checked         INTEGER NOT NULL DEFAULT 0,
        checked_at      TEXT,
        is_manual       INTEGER NOT NULL DEFAULT 0,
        zone            TEXT NOT NULL DEFAULT 'scaffali',
        category_label  TEXT NOT NULL DEFAULT 'Altro',
        meal_id         INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pantry_items (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        ingredient_name TEXT NOT NULL UNIQUE,
        quantity        REAL NOT NULL DEFAULT 0,
        unit            TEXT,
        min_quantity    REAL NOT NULL DEFAULT 0,
        zone            TEXT NOT NULL DEFAULT 'scaffali',
        updated_at      TEXT,
        is_manual       INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ingredient_prices (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        ingredient_name TEXT NOT NULL,
        price           REAL NOT NULL,
        unit            TEXT,
        recorded_at     TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // ── Indici ─────────────────────────────────────────────────────────────────

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_schedule_week    ON schedule(week_start)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_shopping_week    ON shopping_items(week_start)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_meals_system     ON meals(is_system)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pantry_name      ON pantry_items(ingredient_name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ingredients_meal ON meal_ingredients(meal_id)');

    // ── Seed categorie (idempotente) ─────────────────────────────────────────────

    $pdo->exec("INSERT OR IGNORE INTO meal_categories (id, name) VALUES
        (1,'Colazione'),
        (2,'Primo'),
        (3,'Secondo'),
        (4,'Contorno'),
        (5,'Altro')
    ");

    // ── Seed dati da file esterni (solo al primo avvio) ────────────────────────
    try { seedIfEmpty($pdo); } catch (\Throwable $e) {}
}

// ── Rilevamento zona (usato solo nel seed ricette) ────────────────────────────
function detectZoneStatic(string $name): string
{
    $n   = mb_strtolower(trim($name));
    $map = [
        'ortofrutta' => [
            'aglio','cipolla','carota','sedano','zucchine','pomodor','basilico',
            'prezzemolo','menta','rosmarino','limone','funghi','rape','friariell',
            'melanzane','patate','insalata','spinaci','rucola','mele','banane',
            'fragole','arance','avocado','cavolo','broccoli','peperoni','zucca',
            'finocchio','carciofi','asparagi','fagiolini',
        ],
        'pane'       => [
            'pane','cornett','crackers','grissini','fette biscottate','piadina',
            'baguette','ciabatta',
        ],
        'macelleria' => [
            'carne','macinata','bistecca','pollo','guanciale','pancetta',
            'salsiccia','prosciutto','salame','maiale','vitello','agnello',
            'hamburger','wurstel','spiedini','cotoletta','braciole','lombata',
            'abbacchio','tacchino','coniglio','fegato',
        ],
        'pesce'      => [
            'salmone','merluzzo','gamberi','vongole','acciughe','tonno','baccalà',
            'orata','calamari','cozze','pesce','spigola','trota','polpo','seppia',
        ],
        'latticini'  => [
            'mozzarella','parmigiano','pecorino','burro','yogurt','latte','uova',
            'panna','ricotta','mascarpone','fontina','gorgonzola','stracchino',
            'provolone','emmental',
        ],
        'scaffali'   => [
            'spaghetti','penne','pasta','riso','olio','passata','pelati','fagioli',
            'lenticchie','ceci','sale','pepe','brodo','dado','farina','zucchero',
            'miele','marmellata','nutella','caffè','aceto','maionese','pangrattato',
            'lievito','cacao','granola','biscotti','olive','capperi','senape',
            'ketchup','uvetta','pinoli','mandorle','noci',
        ],
        'bevande'    => [
            'acqua','vino','birra','succo','aranciata','coca','limonata',
            'tè','tisana','camomilla',
        ],
        'surgelati'  => [
            'surgelat','gelato','bastoncini','crocchette','pizzette surgelate',
        ],
    ];
    foreach ($map as $zone => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($n, $kw)) return $zone;
        }
    }
    return 'scaffali';
}

// ── Parse ricette.xlsx → array ─────────────────────────────────────────────────
function parseXlsxRecipes(string $path): array
{
    if (!file_exists($path) || !class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    $shXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$shXml) return [];

    // shared strings
    $ss = [];
    if ($ssXml) {
        $d = new DOMDocument();
        $d->loadXML($ssXml);
        foreach ($d->getElementsByTagName('t') as $t) $ss[] = $t->nodeValue;
    }

    $dom = new DOMDocument();
    $dom->loadXML($shXml);
    $xp  = new DOMXPath($dom);
    $xp->registerNamespace('ss', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $catMap  = ['Colazione'=>1,'Primo'=>2,'Secondo'=>3,'Contorno'=>4,'Altro'=>5];
    $recipes = [];
    $first   = true;

    foreach ($xp->query('//ss:sheetData/ss:row') as $row) {
        if ($first) { $first = false; continue; }
        $cols = [];
        foreach ($xp->query('ss:c', $row) as $cell) {
            $col = preg_replace('/[0-9]/', '', $cell->getAttribute('r'));
            $t   = $cell->getAttribute('t');
            $val = '';
            if ($t === 's') {
                $v = $xp->query('ss:v', $cell);
                if ($v->length) $val = $ss[(int)$v->item(0)->nodeValue] ?? '';
            } elseif ($t === 'inlineStr') {
                foreach ($xp->query('.//ss:t', $cell) as $tn) $val .= $tn->nodeValue;
            } else {
                $v = $xp->query('ss:v', $cell);
                if ($v->length) $val = $v->item(0)->nodeValue;
            }
            $cols[$col] = $val;
        }
        $name = trim($cols['B'] ?? '');
        if (!$name) continue;
        $emoji = trim($cols['C'] ?? '🍽️') ?: '🍽️';
        $catId = $catMap[trim($cols['D'] ?? 'Altro')] ?? 5;
        $kcal  = (int)($cols['E'] ?? 0);
        $note  = trim($cols['G'] ?? '');
        $ings  = [];
        foreach (explode("\n", $cols['F'] ?? '') as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 2) {
                $ings[] = ['name'=>$parts[0],'quantity'=>is_numeric($parts[1])?(float)$parts[1]:null,'unit'=>$parts[2]??null];
            }
        }
        $recipes[] = compact('name','emoji','catId','kcal','note','ings');
    }
    return $recipes;
}

// ── Seed primo avvio ──────────────────────────────────────────────────────────────
function seedIfEmpty(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = OFF');

    // 1. nutrition_db da nutrition.json (solo alimenti, niente casalinghi)
    $jsonPath = __DIR__ . '/nutrition.json';
    if (file_exists($jsonPath) &&
        (int)$pdo->query("SELECT COUNT(*) FROM nutrition_db")->fetchColumn() === 0) {
        $items = json_decode(file_get_contents($jsonPath), true) ?? [];
        $ins   = $pdo->prepare(
            "INSERT OR IGNORE INTO nutrition_db
                (name, kcal_100g, zone, price_est, aliases, unit_weights)
             VALUES (?,?,?,?,?,?)"
        );
        foreach ($items as $it) {
            $ins->execute([
                $it['name'],
                (float)($it['kcal'] ?? $it['kcal_100g'] ?? 0),
                $it['zone'] ?? 'scaffali',
                (float)($it['price_est'] ?? 0),
                json_encode($it['aliases']      ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($it['unit_weights'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // 2. Prezzi da price_seed.sql (UPDATE OR IGNORE)
    $sqlPath = __DIR__ . '/price_seed.sql';
    if (file_exists($sqlPath)) {
        try { $pdo->exec(file_get_contents($sqlPath)); } catch (\Throwable $e) {}
    }

    // 3. Ricette di sistema da ricette.xlsx (fallback: hardcoded)
    if ((int)$pdo->query("SELECT COUNT(*) FROM meals WHERE is_system=1")->fetchColumn() === 0) {
        $recipes = parseXlsxRecipes(__DIR__ . '/ricette.xlsx');
        if (empty($recipes)) $recipes = getHardcodedMeals();

        $insMeal = $pdo->prepare(
            "INSERT OR IGNORE INTO meals (name,emoji,category_id,cal_per_adult,notes,is_system)
             VALUES (?,?,?,?,?,1)"
        );
        $insIng = $pdo->prepare(
            "INSERT INTO meal_ingredients (meal_id,name,quantity,unit,zone) VALUES (?,?,?,?,?)"
        );
        foreach ($recipes as $r) {
            $insMeal->execute([$r['name'],$r['emoji'],$r['catId'],$r['kcal'],$r['note']?:null]);
            $mealId = (int)$pdo->lastInsertId();
            if (!$mealId) continue;
            foreach ($r['ings'] as $ing) {
                if (!trim($ing['name'] ?? '')) continue;
                $insIng->execute([$mealId,$ing['name'],$ing['quantity'],$ing['unit'],detectZoneStatic($ing['name'])]);
            }
        }
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
}

// ── Ricette hardcoded di fallback ─────────────────────────────────────────────────
function getHardcodedMeals(): array
{
    return [
        // Colazione
        ['name'=>'Cornetto e caffè','emoji'=>'☕','catId'=>1,'kcal'=>310,'note'=>'',
         'ings'=>[['name'=>'Cornetti','quantity'=>2,'unit'=>'pz'],['name'=>'Zucchero','quantity'=>2,'unit'=>'cucchiaini']]],
        ['name'=>'Yogurt e frutta','emoji'=>'🍓','catId'=>1,'kcal'=>180,'note'=>'',
         'ings'=>[['name'=>'Yogurt greco','quantity'=>150,'unit'=>'g'],['name'=>'Fragole','quantity'=>100,'unit'=>'g'],['name'=>'Miele','quantity'=>1,'unit'=>'cucchiaio'],['name'=>'Granola','quantity'=>30,'unit'=>'g']]],
        ['name'=>'Uova strapazzate e toast','emoji'=>'🍞','catId'=>1,'kcal'=>350,'note'=>'',
         'ings'=>[['name'=>'Uova','quantity'=>3,'unit'=>'pz'],['name'=>'Pane in cassetta','quantity'=>2,'unit'=>'fette'],['name'=>'Burro','quantity'=>10,'unit'=>'g']]],
        ['name'=>'Latte e cereali','emoji'=>'🥛','catId'=>1,'kcal'=>280,'note'=>'',
         'ings'=>[['name'=>'Latte intero','quantity'=>200,'unit'=>'ml'],['name'=>'Granola','quantity'=>60,'unit'=>'g']]],
        ['name'=>'Pane tostato e marmellata','emoji'=>'🍯','catId'=>1,'kcal'=>290,'note'=>'',
         'ings'=>[['name'=>'Pane in cassetta','quantity'=>3,'unit'=>'fette'],['name'=>'Burro','quantity'=>15,'unit'=>'g'],['name'=>'Marmellata','quantity'=>40,'unit'=>'g']]],
        ['name'=>'Pancakes','emoji'=>'🥞','catId'=>1,'kcal'=>380,'note'=>'',
         'ings'=>[['name'=>'Farina 00','quantity'=>150,'unit'=>'g'],['name'=>'Uova','quantity'=>2,'unit'=>'pz'],['name'=>'Latte intero','quantity'=>200,'unit'=>'ml'],['name'=>'Zucchero','quantity'=>2,'unit'=>'cucchiai'],['name'=>'Burro','quantity'=>20,'unit'=>'g']]],
        ['name'=>'Avocado toast','emoji'=>'🥑','catId'=>1,'kcal'=>350,'note'=>'',
         'ings'=>[['name'=>'Pane integrale','quantity'=>2,'unit'=>'fette'],['name'=>'Avocado','quantity'=>1,'unit'=>'pz'],['name'=>'Uova','quantity'=>1,'unit'=>'pz'],['name'=>'Limone','quantity'=>1,'unit'=>'pz']]],
        ['name'=>'Fette biscottate e miele','emoji'=>'🍯','catId'=>1,'kcal'=>260,'note'=>'',
         'ings'=>[['name'=>'Fette biscottate','quantity'=>4,'unit'=>'pz'],['name'=>'Miele','quantity'=>2,'unit'=>'cucchiai']]],
        ['name'=>'Pane e nutella','emoji'=>'🍫','catId'=>1,'kcal'=>380,'note'=>'',
         'ings'=>[['name'=>'Pane comune','quantity'=>2,'unit'=>'fette'],['name'=>'Nutella','quantity'=>40,'unit'=>'g']]],
        // Primo
        ['name'=>'Spaghetti al sugo','emoji'=>'🍝','catId'=>2,'kcal'=>520,'note'=>'',
         'ings'=>[['name'=>'Spaghetti','quantity'=>320,'unit'=>'g'],['name'=>'Passata di pomodoro','quantity'=>400,'unit'=>'g'],['name'=>'Aglio','quantity'=>2,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai'],['name'=>'Basilico','quantity'=>1,'unit'=>'mazzo']]],
        ['name'=>'Carbonara','emoji'=>'🥚','catId'=>2,'kcal'=>650,'note'=>'',
         'ings'=>[['name'=>'Spaghetti','quantity'=>320,'unit'=>'g'],['name'=>'Guanciale','quantity'=>150,'unit'=>'g'],['name'=>'Uova','quantity'=>4,'unit'=>'pz'],['name'=>'Pecorino Romano','quantity'=>80,'unit'=>'g']]],
        ['name'=>'Risotto ai funghi','emoji'=>'🍄','catId'=>2,'kcal'=>490,'note'=>'',
         'ings'=>[['name'=>'Riso Carnaroli','quantity'=>320,'unit'=>'g'],['name'=>'Funghi porcini','quantity'=>200,'unit'=>'g'],['name'=>'Cipolla','quantity'=>1,'unit'=>'pz'],['name'=>'Vino bianco','quantity'=>1,'unit'=>'bicchiere'],['name'=>'Parmigiano','quantity'=>50,'unit'=>'g'],['name'=>'Burro','quantity'=>30,'unit'=>'g']]],
        ['name'=>'Pasta e fagioli','emoji'=>'🫘','catId'=>2,'kcal'=>440,'note'=>'',
         'ings'=>[['name'=>'Pasta mista','quantity'=>300,'unit'=>'g'],['name'=>'Fagioli borlotti','quantity'=>400,'unit'=>'g'],['name'=>'Cipolla','quantity'=>1,'unit'=>'pz'],['name'=>'Carota','quantity'=>1,'unit'=>'pz'],['name'=>'Rosmarino','quantity'=>1,'unit'=>'rametto']]],
        ['name'=>'Penne all\'arrabbiata','emoji'=>'🌶️','catId'=>2,'kcal'=>480,'note'=>'',
         'ings'=>[['name'=>'Penne','quantity'=>320,'unit'=>'g'],['name'=>'Passata di pomodoro','quantity'=>400,'unit'=>'g'],['name'=>'Peperoncino','quantity'=>2,'unit'=>'pz'],['name'=>'Aglio','quantity'=>3,'unit'=>'spicchi']]],
        ['name'=>'Lasagne al ragù','emoji'=>'🫕','catId'=>2,'kcal'=>720,'note'=>'',
         'ings'=>[['name'=>'Sfoglie lasagne','quantity'=>500,'unit'=>'g'],['name'=>'Carne macinata mista','quantity'=>400,'unit'=>'g'],['name'=>'Besciamella','quantity'=>500,'unit'=>'ml'],['name'=>'Passata di pomodoro','quantity'=>400,'unit'=>'g'],['name'=>'Parmigiano','quantity'=>100,'unit'=>'g']]],
        ['name'=>'Cacio e pepe','emoji'=>'⚫','catId'=>2,'kcal'=>580,'note'=>'',
         'ings'=>[['name'=>'Spaghetti','quantity'=>320,'unit'=>'g'],['name'=>'Pecorino Romano','quantity'=>100,'unit'=>'g'],['name'=>'Pepe nero','quantity'=>2,'unit'=>'cucchiaini']]],
        ['name'=>'Amatriciana','emoji'=>'🧅','catId'=>2,'kcal'=>620,'note'=>'',
         'ings'=>[['name'=>'Rigatoni','quantity'=>320,'unit'=>'g'],['name'=>'Guanciale','quantity'=>150,'unit'=>'g'],['name'=>'Pomodori pelati','quantity'=>400,'unit'=>'g'],['name'=>'Pecorino Romano','quantity'=>60,'unit'=>'g']]],
        ['name'=>'Pasta al salmone','emoji'=>'🐟','catId'=>2,'kcal'=>530,'note'=>'',
         'ings'=>[['name'=>'Penne','quantity'=>320,'unit'=>'g'],['name'=>'Salmone fresco','quantity'=>200,'unit'=>'g'],['name'=>'Panna da cucina','quantity'=>100,'unit'=>'ml'],['name'=>'Cipolla','quantity'=>1,'unit'=>'pz']]],
        ['name'=>'Pasta con le vongole','emoji'=>'🐚','catId'=>2,'kcal'=>480,'note'=>'',
         'ings'=>[['name'=>'Linguine','quantity'=>320,'unit'=>'g'],['name'=>'Vongole','quantity'=>500,'unit'=>'g'],['name'=>'Aglio','quantity'=>3,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>4,'unit'=>'cucchiai'],['name'=>'Vino bianco','quantity'=>100,'unit'=>'ml']]],
        ['name'=>'Pasta al pesto','emoji'=>'🌿','catId'=>2,'kcal'=>510,'note'=>'',
         'ings'=>[['name'=>'Trofie','quantity'=>320,'unit'=>'g'],['name'=>'Pesto genovese','quantity'=>80,'unit'=>'g'],['name'=>'Patate','quantity'=>100,'unit'=>'g'],['name'=>'Fagiolini','quantity'=>80,'unit'=>'g']]],
        ['name'=>'Pasta burro e parmigiano','emoji'=>'🧀','catId'=>2,'kcal'=>490,'note'=>'Ottima per i bambini',
         'ings'=>[['name'=>'Spaghetti','quantity'=>320,'unit'=>'g'],['name'=>'Burro','quantity'=>60,'unit'=>'g'],['name'=>'Parmigiano','quantity'=>80,'unit'=>'g']]],
        ['name'=>'Pasta aglio olio','emoji'=>'🧄','catId'=>2,'kcal'=>420,'note'=>'10 minuti',
         'ings'=>[['name'=>'Spaghetti','quantity'=>320,'unit'=>'g'],['name'=>'Aglio','quantity'=>4,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>5,'unit'=>'cucchiai'],['name'=>'Peperoncino','quantity'=>2,'unit'=>'pz']]],
        ['name'=>'Minestrone','emoji'=>'🥦','catId'=>2,'kcal'=>260,'note'=>'',
         'ings'=>[['name'=>'Zucchine','quantity'=>2,'unit'=>'pz'],['name'=>'Carota','quantity'=>2,'unit'=>'pz'],['name'=>'Patate','quantity'=>2,'unit'=>'pz'],['name'=>'Fagiolini','quantity'=>150,'unit'=>'g'],['name'=>'Pasta mista','quantity'=>150,'unit'=>'g']]],
        ['name'=>'Zuppa di lenticchie','emoji'=>'🥣','catId'=>2,'kcal'=>320,'note'=>'',
         'ings'=>[['name'=>'Lenticchie','quantity'=>300,'unit'=>'g'],['name'=>'Carota','quantity'=>2,'unit'=>'pz'],['name'=>'Sedano','quantity'=>2,'unit'=>'coste'],['name'=>'Cipolla','quantity'=>1,'unit'=>'pz'],['name'=>'Pomodori pelati','quantity'=>400,'unit'=>'g']]],
        ['name'=>'Pasta col tonno','emoji'=>'🐟','catId'=>2,'kcal'=>450,'note'=>'',
         'ings'=>[['name'=>'Penne','quantity'=>320,'unit'=>'g'],['name'=>'Tonno in scatola','quantity'=>160,'unit'=>'g'],['name'=>'Pomodori pelati','quantity'=>400,'unit'=>'g'],['name'=>'Olive','quantity'=>50,'unit'=>'g'],['name'=>'Capperi','quantity'=>1,'unit'=>'cucchiaio']]],
        // Secondo
        ['name'=>'Pollo arrosto','emoji'=>'🍗','catId'=>3,'kcal'=>380,'note'=>'',
         'ings'=>[['name'=>'Pollo intero','quantity'=>1200,'unit'=>'g'],['name'=>'Rosmarino','quantity'=>2,'unit'=>'rametti'],['name'=>'Aglio','quantity'=>3,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai'],['name'=>'Patate','quantity'=>600,'unit'=>'g']]],
        ['name'=>'Bistecca alla fiorentina','emoji'=>'🥩','catId'=>3,'kcal'=>680,'note'=>'',
         'ings'=>[['name'=>'Bistecca di manzo','quantity'=>600,'unit'=>'g'],['name'=>'Rosmarino','quantity'=>1,'unit'=>'rametto'],['name'=>'Olio EVO','quantity'=>1,'unit'=>'cucchiaio']]],
        ['name'=>'Cotoletta alla milanese','emoji'=>'🍳','catId'=>3,'kcal'=>520,'note'=>'Ottima per i bambini',
         'ings'=>[['name'=>'Cotoletta di vitello','quantity'=>500,'unit'=>'g'],['name'=>'Uova','quantity'=>2,'unit'=>'pz'],['name'=>'Pangrattato','quantity'=>100,'unit'=>'g'],['name'=>'Burro','quantity'=>50,'unit'=>'g']]],
        ['name'=>'Polpette al sugo','emoji'=>'🍖','catId'=>3,'kcal'=>420,'note'=>'',
         'ings'=>[['name'=>'Carne macinata mista','quantity'=>400,'unit'=>'g'],['name'=>'Uova','quantity'=>1,'unit'=>'pz'],['name'=>'Pane raffermo','quantity'=>50,'unit'=>'g'],['name'=>'Parmigiano','quantity'=>30,'unit'=>'g'],['name'=>'Passata di pomodoro','quantity'=>400,'unit'=>'g']]],
        ['name'=>'Salmone al forno','emoji'=>'🐟','catId'=>3,'kcal'=>350,'note'=>'',
         'ings'=>[['name'=>'Salmone fresco','quantity'=>600,'unit'=>'g'],['name'=>'Limone','quantity'=>1,'unit'=>'pz'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai']]],
        ['name'=>'Frittata di zucchine','emoji'=>'🍳','catId'=>3,'kcal'=>290,'note'=>'',
         'ings'=>[['name'=>'Uova','quantity'=>4,'unit'=>'pz'],['name'=>'Zucchine','quantity'=>2,'unit'=>'pz'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai'],['name'=>'Parmigiano','quantity'=>30,'unit'=>'g']]],
        ['name'=>'Pollo al forno con patate','emoji'=>'🍗','catId'=>3,'kcal'=>410,'note'=>'',
         'ings'=>[['name'=>'Petto di pollo','quantity'=>500,'unit'=>'g'],['name'=>'Patate','quantity'=>500,'unit'=>'g'],['name'=>'Rosmarino','quantity'=>2,'unit'=>'rametti'],['name'=>'Aglio','quantity'=>2,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai']]],
        ['name'=>'Scaloppine al limone','emoji'=>'🍋','catId'=>3,'kcal'=>320,'note'=>'',
         'ings'=>[['name'=>'Cotoletta di vitello','quantity'=>500,'unit'=>'g'],['name'=>'Limone','quantity'=>2,'unit'=>'pz'],['name'=>'Farina 00','quantity'=>30,'unit'=>'g'],['name'=>'Burro','quantity'=>30,'unit'=>'g']]],
        ['name'=>'Salsicce e friarielli','emoji'=>'🌿','catId'=>3,'kcal'=>480,'note'=>'',
         'ings'=>[['name'=>'Salsiccia','quantity'=>400,'unit'=>'g'],['name'=>'Rape','quantity'=>400,'unit'=>'g'],['name'=>'Aglio','quantity'=>2,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai']]],
        ['name'=>'Melanzane alla parmigiana','emoji'=>'🍆','catId'=>3,'kcal'=>420,'note'=>'',
         'ings'=>[['name'=>'Melanzane','quantity'=>2,'unit'=>'pz'],['name'=>'Passata di pomodoro','quantity'=>400,'unit'=>'g'],['name'=>'Mozzarella','quantity'=>200,'unit'=>'g'],['name'=>'Parmigiano','quantity'=>80,'unit'=>'g']]],
        ['name'=>'Bastoncini di pesce','emoji'=>'🐟','catId'=>3,'kcal'=>340,'note'=>'Dal freezer',
         'ings'=>[['name'=>'Bastoncini di pesce','quantity'=>400,'unit'=>'g'],['name'=>'Limone','quantity'=>1,'unit'=>'pz']]],
        ['name'=>'Wurstel e patatine','emoji'=>'🌭','catId'=>3,'kcal'=>480,'note'=>'',
         'ings'=>[['name'=>'Wurstel','quantity'=>4,'unit'=>'pz'],['name'=>'Patate','quantity'=>400,'unit'=>'g'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai']]],
        ['name'=>'Hamburger in padella','emoji'=>'🍔','catId'=>3,'kcal'=>420,'note'=>'',
         'ings'=>[['name'=>'Carne macinata di manzo','quantity'=>400,'unit'=>'g'],['name'=>'Cipolla','quantity'=>1,'unit'=>'pz'],['name'=>'Olio EVO','quantity'=>1,'unit'=>'cucchiaio']]],
        ['name'=>'Tonno e fagioli','emoji'=>'🐟','catId'=>3,'kcal'=>310,'note'=>'',
         'ings'=>[['name'=>'Tonno in scatola','quantity'=>160,'unit'=>'g'],['name'=>'Fagioli cannellini','quantity'=>400,'unit'=>'g'],['name'=>'Cipolla rossa','quantity'=>1,'unit'=>'pz'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai']]],
        // Contorno
        ['name'=>'Insalata mista','emoji'=>'🥗','catId'=>4,'kcal'=>120,'note'=>'',
         'ings'=>[['name'=>'Insalata','quantity'=>1,'unit'=>'cespo'],['name'=>'Pomodori','quantity'=>2,'unit'=>'pz'],['name'=>'Carota','quantity'=>1,'unit'=>'pz'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai']]],
        ['name'=>'Patate al forno','emoji'=>'🥔','catId'=>4,'kcal'=>280,'note'=>'',
         'ings'=>[['name'=>'Patate','quantity'=>600,'unit'=>'g'],['name'=>'Rosmarino','quantity'=>2,'unit'=>'rametti'],['name'=>'Aglio','quantity'=>2,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai']]],
        ['name'=>'Verdure grigliate','emoji'=>'🥗','catId'=>4,'kcal'=>160,'note'=>'',
         'ings'=>[['name'=>'Zucchine','quantity'=>2,'unit'=>'pz'],['name'=>'Melanzane','quantity'=>1,'unit'=>'pz'],['name'=>'Peperoni','quantity'=>2,'unit'=>'pz'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai']]],
        ['name'=>'Spinaci in padella','emoji'=>'🥬','catId'=>4,'kcal'=>100,'note'=>'',
         'ings'=>[['name'=>'Spinaci','quantity'=>400,'unit'=>'g'],['name'=>'Aglio','quantity'=>1,'unit'=>'spicchio'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai']]],
        ['name'=>'Broccoli ripassati','emoji'=>'🥦','catId'=>4,'kcal'=>130,'note'=>'',
         'ings'=>[['name'=>'Broccoli','quantity'=>500,'unit'=>'g'],['name'=>'Aglio','quantity'=>2,'unit'=>'spicchi'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai']]],
        ['name'=>'Insalata caprese','emoji'=>'🥗','catId'=>4,'kcal'=>280,'note'=>'',
         'ings'=>[['name'=>'Mozzarella di bufala','quantity'=>250,'unit'=>'g'],['name'=>'Pomodori','quantity'=>4,'unit'=>'pz'],['name'=>'Basilico','quantity'=>1,'unit'=>'mazzo'],['name'=>'Olio EVO','quantity'=>2,'unit'=>'cucchiai']]],
        // Altro
        ['name'=>'Pizza Margherita','emoji'=>'🍕','catId'=>5,'kcal'=>600,'note'=>'',
         'ings'=>[['name'=>'Pizza base','quantity'=>500,'unit'=>'g'],['name'=>'Passata di pomodoro','quantity'=>200,'unit'=>'g'],['name'=>'Mozzarella','quantity'=>200,'unit'=>'g'],['name'=>'Basilico','quantity'=>1,'unit'=>'mazzo']]],
        ['name'=>'Pizza con wurstel','emoji'=>'🌭','catId'=>5,'kcal'=>640,'note'=>'Per i bambini',
         'ings'=>[['name'=>'Pizza base','quantity'=>300,'unit'=>'g'],['name'=>'Passata di pomodoro','quantity'=>150,'unit'=>'g'],['name'=>'Mozzarella','quantity'=>150,'unit'=>'g'],['name'=>'Wurstel','quantity'=>3,'unit'=>'pz']]],
        ['name'=>'Piadina con prosciutto','emoji'=>'🫓','catId'=>5,'kcal'=>480,'note'=>'',
         'ings'=>[['name'=>'Piadina','quantity'=>2,'unit'=>'pz'],['name'=>'Prosciutto crudo','quantity'=>80,'unit'=>'g'],['name'=>'Rucola','quantity'=>50,'unit'=>'g'],['name'=>'Stracchino','quantity'=>80,'unit'=>'g']]],
        ['name'=>'Bruschette al pomodoro','emoji'=>'🍅','catId'=>5,'kcal'=>320,'note'=>'',
         'ings'=>[['name'=>'Pane comune','quantity'=>4,'unit'=>'fette'],['name'=>'Pomodori','quantity'=>4,'unit'=>'pz'],['name'=>'Aglio','quantity'=>1,'unit'=>'spicchio'],['name'=>'Basilico','quantity'=>1,'unit'=>'mazzo'],['name'=>'Olio EVO','quantity'=>3,'unit'=>'cucchiai']]],
        ['name'=>'Toast prosciutto e formaggio','emoji'=>'🧀','catId'=>5,'kcal'=>380,'note'=>'',
         'ings'=>[['name'=>'Pane in cassetta','quantity'=>4,'unit'=>'fette'],['name'=>'Prosciutto cotto','quantity'=>60,'unit'=>'g'],['name'=>'Fontina','quantity'=>60,'unit'=>'g'],['name'=>'Burro','quantity'=>10,'unit'=>'g']]],
    ];
}
