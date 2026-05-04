<?php

/**
 * Cerca kcal/100g per nome ingrediente in nutrition_db.
 * Prova: match esatto → aliases → match parziale (contains).
 */
function lookupKcal(PDO $pdo, string $name): ?float
{
    $name = mb_strtolower(trim($name));
    if (!$name) return null;

    // 1. match esatto
    $st = $pdo->prepare('SELECT kcal_100g FROM nutrition_db WHERE LOWER(name)=? LIMIT 1');
    $st->execute([$name]);
    if ($row = $st->fetch()) return (float)$row['kcal_100g'];

    // 2. match su aliases
    foreach ($pdo->query('SELECT kcal_100g, aliases FROM nutrition_db')->fetchAll() as $row) {
        $aliases = json_decode($row['aliases'], true) ?? [];
        foreach ($aliases as $a) {
            if (mb_strtolower($a) === $name) return (float)$row['kcal_100g'];
        }
    }

    // 3. match parziale
    $st = $pdo->prepare('SELECT kcal_100g FROM nutrition_db WHERE LOWER(name) LIKE ? LIMIT 1');
    $st->execute(["%{$name}%"]);
    if ($row = $st->fetch()) return (float)$row['kcal_100g'];

    return null;
}

/**
 * Converte quantità + unità in grammi.
 * Per unità pezzo/fetta/ecc. consulta unit_weights in nutrition_db.
 */
function toGrams(float $qty, string $unit, ?string $ingredientName, PDO $pdo): ?float
{
    $unit = mb_strtolower(trim($unit));

    $direct = ['g'=>1.0,'kg'=>1000.0,'ml'=>1.0,'l'=>1000.0,'cl'=>10.0,'dl'=>100.0];
    if (isset($direct[$unit])) return $qty * $direct[$unit];

    $volume = [
        'cucchiaio'=>15.0,'cucchiai'=>15.0,
        'cucchiaino'=>5.0,'cucchiaini'=>5.0,
        'tazza'=>240.0,'bicchiere'=>200.0,
        'mazzo'=>30.0,'rametto'=>5.0,'rametti'=>5.0,
        'foglia'=>2.0,'foglie'=>2.0,
        'bustina'=>8.0,'noce'=>10.0,
        'spruzzo'=>3.0,'pizzico'=>0.5,
        'spicchio'=>5.0,'spicchi'=>5.0,
    ];
    if (isset($volume[$unit])) return $qty * $volume[$unit];

    $pieceUnits = [
        'pz','pezzo','pezzi','fetta','fette','spicchio','spicchi',
        'filetto','filetti','lattina','lattine','vasetto','vasetti',
        'pallina','grappolo','cespo','pannocchia','dado','cubetto',
        'testa','trancio','bottiglia','bottiglie','tazzina',
    ];
    if (in_array($unit, $pieceUnits, true) && $ingredientName) {
        $iname = mb_strtolower(trim($ingredientName));
        $row   = $pdo->prepare(
            'SELECT unit_weights FROM nutrition_db WHERE LOWER(name) LIKE ? LIMIT 1'
        );
        $row->execute(["%{$iname}%"]);
        if ($r = $row->fetch()) {
            $weights = json_decode($r['unit_weights'], true) ?? [];
            $w = $weights[$unit] ?? $weights['pz'] ?? null;
            if ($w !== null) return $qty * (float)$w;
        }
    }

    if (in_array($unit, ['q.b.','qb','q.b','n/a','a piacere',''], true)) return 0.0;

    return null;
}

/**
 * Calorie di un singolo ingrediente.
 * Ritorna ['grams'=>float|null, 'kcal'=>float|null, 'found'=>bool]
 */
function calcIngredientKcal(PDO $pdo, string $name, float $qty, string $unit): array
{
    $grams   = toGrams($qty, $unit, $name, $pdo);
    $kcal100 = lookupKcal($pdo, $name);

    if ($grams === null || $kcal100 === null) {
        return ['grams'=>null,'kcal'=>null,'found'=>false];
    }
    return [
        'grams' => round($grams, 1),
        'kcal'  => round(($grams / 100.0) * $kcal100, 1),
        'found' => true,
    ];
}

/**
 * Calorie totali di un piatto.
 * $ingredients = [['name'=>'...','quantity'=>320,'unit'=>'g'], ...]
 */
function calcMealKcal(PDO $pdo, array $ingredients): array
{
    $total   = 0.0;
    $missing = [];
    foreach ($ingredients as $ing) {
        $res = calcIngredientKcal(
            $pdo,
            $ing['name'],
            (float)($ing['quantity'] ?? 0),
            (string)($ing['unit'] ?? '')
        );
        if ($res['found']) {
            $total += $res['kcal'];
        } else {
            $missing[] = $ing['name'];
        }
    }
    return [
        'kcal_totali'     => round($total),
        'ingredienti_ok'  => count($ingredients) - count($missing),
        'ingredienti_tot' => count($ingredients),
        'non_trovati'     => $missing,
    ];
}

/**
 * Calorie per porzione, scalate per i profili famiglia.
 * $profili = [['name'=>'...','portion_weight'=>1.0], ...]
 */
function calcMealKcalPerPorzione(PDO $pdo, array $ingredients, array $profili): array
{
    $meal     = calcMealKcal($pdo, $ingredients);
    $totPeso  = array_sum(array_column($profili, 'portion_weight')) ?: 1.0;
    $nProfili = count($profili);

    if ($nProfili === 0) return $meal;

    $meal['porzioni_totali']  = $totPeso;
    $meal['kcal_per_adulto']  = round($meal['kcal_totali'] / $totPeso);
    $meal['kcal_per_profilo'] = array_map(
        fn($p) => [
            'name' => $p['name'] ?? '?',
            'kcal' => round($meal['kcal_totali'] * ($p['portion_weight'] / $totPeso)),
        ],
        $profili
    );

    return $meal;
}
