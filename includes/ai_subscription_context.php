<?php

require_once __DIR__ . '/currency_rates.php';
require_once __DIR__ . '/subscription_value_score.php';
require_once __DIR__ . '/upcoming_payments.php';

function ai_user_financial_context($db, $userId)
{
    $userStmt = $db->prepare('SELECT main_currency, language, budget, period_budget,
        budget_period_type, budget_period_anchor_date
        FROM user WHERE id = :userId');
    if (!$userStmt) {
        return null;
    }
    $userStmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $userResult = $userStmt->execute();
    $user = $userResult ? $userResult->fetchArray(SQLITE3_ASSOC) : false;
    if (!$user) {
        return null;
    }

    $settingsStmt = $db->prepare('SELECT display_currency_id FROM settings WHERE user_id = :userId');
    if (!$settingsStmt) {
        return null;
    }
    $settingsStmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $settingsResult = $settingsStmt->execute();
    $settings = $settingsResult ? $settingsResult->fetchArray(SQLITE3_ASSOC) : false;

    $currencies = [];
    $currencyStmt = $db->prepare('SELECT id, code, symbol FROM currencies WHERE user_id = :userId');
    if (!$currencyStmt) {
        return null;
    }
    $currencyStmt->bindValue(':userId', (int) $userId, SQLITE3_INTEGER);
    $currencyResult = $currencyStmt->execute();
    while ($currencyResult && ($row = $currencyResult->fetchArray(SQLITE3_ASSOC))) {
        $currencies[(int) $row['id']] = $row;
    }

    $mainCurrencyId = (int) ($user['main_currency'] ?? 0);
    $targetCurrencyId = dashboard_preferred_currency_id(
        $mainCurrencyId,
        $settings ?: [],
        $currencies
    );
    if (!isset($currencies[$targetCurrencyId]) && isset($currencies[$mainCurrencyId])) {
        $targetCurrencyId = $mainCurrencyId;
    }
    if (!isset($currencies[$targetCurrencyId])) {
        return null;
    }

    return [
        'user' => $user,
        'currencies' => $currencies,
        'main_currency_id' => $mainCurrencyId,
        'currency_id' => $targetCurrencyId,
        'currency_code' => (string) $currencies[$targetCurrencyId]['code'],
        'currency_rates' => diamon_currency_rates($db, (int) $userId),
    ];
}

function ai_convert_amount_to_currency($amount, $sourceCurrencyId, $targetCurrencyId, array $currencyRates)
{
    if (!is_numeric($amount) || !is_finite((float) $amount)) {
        return null;
    }

    $sourceCurrencyId = (int) $sourceCurrencyId;
    $targetCurrencyId = (int) $targetCurrencyId;
    if ($sourceCurrencyId <= 0 || $targetCurrencyId <= 0) {
        return null;
    }
    if ($sourceCurrencyId === $targetCurrencyId) {
        return (float) $amount;
    }

    $sourceRate = (float) ($currencyRates[$sourceCurrencyId] ?? 0);
    $targetRate = (float) ($currencyRates[$targetCurrencyId] ?? 0);
    if ($sourceRate <= 0 || $targetRate <= 0 || !is_finite($sourceRate) || !is_finite($targetRate)) {
        return null;
    }

    return (float) $amount / $sourceRate * $targetRate;
}

function ai_user_budget_context(array $financialContext)
{
    $user = $financialContext['user'] ?? [];
    $budgetContext = [];
    foreach ([
        'monthly_budget' => $user['budget'] ?? 0,
        'period_budget' => $user['period_budget'] ?? 0,
    ] as $budgetKey => $budgetAmount) {
        if ((float) $budgetAmount <= 0) {
            continue;
        }
        $convertedBudget = ai_convert_amount_to_currency(
            $budgetAmount,
            $financialContext['main_currency_id'],
            $financialContext['currency_id'],
            $financialContext['currency_rates']
        );
        $budgetContext[$budgetKey] = $convertedBudget === null ? null : round($convertedBudget, 2);
    }
    if ((float) ($user['period_budget'] ?? 0) > 0) {
        $budgetContext['period_type'] = $user['budget_period_type'] ?? null;
    }

    return $budgetContext;
}

function ai_subscription_cost_context(array $subscriptions, array $currencies, $targetCurrencyId, array $currencyRates)
{
    $cycleNames = [
        1 => 'daily',
        2 => 'weekly',
        3 => 'monthly',
        4 => 'yearly',
        5 => 'one-time',
    ];
    $items = [];
    $activeCount = 0;
    $activeRecurringCount = 0;
    $unavailableRecurringCount = 0;
    $monthlyTotal = 0.0;
    $annualTotal = 0.0;

    foreach ($subscriptions as $subscription) {
        $subscriptionId = (int) ($subscription['id'] ?? 0);
        $sourceCurrencyId = (int) ($subscription['currency_id'] ?? 0);
        $cycle = (int) ($subscription['cycle'] ?? 0);
        $frequency = max(1, (int) ($subscription['frequency'] ?? 1));
        $active = (int) ($subscription['inactive'] ?? 0) === 0;
        $sourceAmount = isset($currencies[$sourceCurrencyId])
            ? ai_convert_amount_to_currency(
                $subscription['price'] ?? null,
                $sourceCurrencyId,
                (int) $targetCurrencyId,
                $currencyRates
            )
            : null;
        $price = $sourceAmount === null ? null : round($sourceAmount, 2);
        $monthlyCost = null;
        $annualCost = null;
        $oneTimeCost = null;

        if ($cycle >= 1 && $cycle <= 4) {
            if ($sourceAmount !== null) {
                $monthlyAmount = subscription_monthly_price($cycle, $frequency, $sourceAmount);
                $monthlyCost = round($monthlyAmount, 2);
                $annualCost = round($monthlyAmount * 12, 2);
                if ($active) {
                    $activeRecurringCount++;
                    $monthlyTotal += $monthlyAmount;
                    $annualTotal += $monthlyAmount * 12;
                }
            } elseif ($active) {
                $activeRecurringCount++;
                $unavailableRecurringCount++;
            }
        } elseif ($cycle === 5 && $sourceAmount !== null) {
            $oneTimeCost = $price;
        } elseif ($active && $cycle !== 5) {
            $unavailableRecurringCount++;
        }

        if ($active) {
            $activeCount++;
        }

        $items[$subscriptionId] = [
            'name' => (string) ($subscription['name'] ?? ''),
            'status' => $active ? 'active' : 'inactive',
            'billing_cycle' => $cycleNames[$cycle] ?? 'unknown',
            'frequency' => $frequency,
            'price' => $price,
            'monthly_cost' => $monthlyCost,
            'annualized_cost' => $annualCost,
            'one_time_cost' => $oneTimeCost,
            'next_payment' => (string) ($subscription['next_payment'] ?? ''),
            'conversion_available' => $price !== null,
        ];
    }

    $complete = $unavailableRecurringCount === 0;

    return [
        'subscriptions' => $items,
        'totals' => [
            'active_subscriptions' => $activeCount,
            'active_recurring_subscriptions' => $activeRecurringCount,
            'monthly_cost' => $complete ? round($monthlyTotal, 2) : null,
            'annualized_cost' => $complete ? round($annualTotal, 2) : null,
            'complete' => $complete,
            'unavailable_recurring_count' => $unavailableRecurringCount,
        ],
    ];
}



// The currency's everyday name in the user's language, from ICU's data:
// "pesos colombianos" for COP in Spanish, "US dollars" for USD in English.
// Falls back to the ISO code when the intl extension or the name is missing.
function ai_currency_name($currencyCode, $language)
{
    $currencyCode = strtoupper(trim((string) $currencyCode));
    $names = ['singular' => $currencyCode, 'plural' => $currencyCode];
    if ($currencyCode === '' || !class_exists('ResourceBundle')) {
        return $names;
    }

    // Diamon's language keys look like "pt_br" or "zh_cn"; ICU wants "pt_BR".
    $parts = explode('_', str_replace('-', '_', (string) $language), 2);
    $locale = strtolower($parts[0]) . (isset($parts[1]) ? '_' . strtoupper($parts[1]) : '');
    $bundle = @ResourceBundle::create($locale, 'ICUDATA-curr');
    if (!$bundle) {
        return $names;
    }

    $currencies = $bundle['Currencies'];
    $singular = $currencies && $currencies[$currencyCode] ? $currencies[$currencyCode][1] : null;
    $plurals = $bundle['CurrencyPlurals'];
    $plural = $plurals && $plurals[$currencyCode] ? $plurals[$currencyCode]['other'] : null;

    if (is_string($singular) && $singular !== '') {
        $names['singular'] = $singular;
        $names['plural'] = $singular;
    }
    if (is_string($plural) && $plural !== '') {
        $names['plural'] = $plural;
    }

    return $names;
}

// Safety net for the chat reply: the model is told to name the currency, but
// if it still writes the ISO code ("COP 30.000", "30.000 COP", "en COP"), turn
// it into "30.000 pesos colombianos". Other text is left alone.
function ai_reply_with_currency_name($reply, $currencyCode, $currencyName)
{
    $currencyCode = strtoupper(trim((string) $currencyCode));
    $currencyName = trim((string) $currencyName);
    if ($currencyCode === '' || $currencyName === '' || $currencyName === $currencyCode) {
        return (string) $reply;
    }

    $code = preg_quote($currencyCode, '/');
    $amount = '-?\d[\d.,\x{00A0}\x{202F}\']*\d|-?\d';
    $reply = preg_replace('/\b' . $code . '[\s\x{00A0}]?(' . $amount . ')/u', '$1 ' . $currencyName, (string) $reply);
    $reply = preg_replace('/(' . $amount . ')[\s\x{00A0}]?' . $code . '\b/u', '$1 ' . $currencyName, $reply);

    return preg_replace('/\b' . $code . '\b/u', $currencyName, $reply);
}
