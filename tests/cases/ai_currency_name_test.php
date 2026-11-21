<?php

require_once DIAMON_ROOT . '/includes/ai_subscription_context.php';

diamon_test('Dia names the currency in the user language, or falls back to the code', function () {
    $names = ai_currency_name('cop', 'es');
    if (class_exists('ResourceBundle')) {
        assert_same('pesos colombianos', $names['plural'], 'COP in Spanish is called pesos colombianos');
        assert_same('US dollars', ai_currency_name('USD', 'en')['plural'], 'USD in English is called US dollars');
        assert_same('Pesos colombianos', ai_currency_name('COP', 'pt_br')['plural'], 'Diamon language keys like pt_br are understood');
    } else {
        assert_same('COP', $names['plural'], 'without intl the ISO code is the fallback');
    }
    assert_same('', ai_currency_name('', 'es')['plural'], 'no code gives no name');
});

diamon_test('a leftover ISO code in a Dia reply becomes the currency name', function () {
    $name = 'pesos colombianos';
    assert_same('Pagas 30.000 pesos colombianos al mes.', ai_reply_with_currency_name('Pagas COP 30.000 al mes.', 'COP', $name), 'code before the amount');
    assert_same('Pagas 30,000.50 pesos colombianos al mes.', ai_reply_with_currency_name('Pagas 30,000.50 COP al mes.', 'COP', $name), 'code after the amount');
    assert_same('Todo está en pesos colombianos.', ai_reply_with_currency_name('Todo está en COP.', 'COP', $name), 'a bare code');
    assert_same('Netflix cuesta 30.000 pesos.', ai_reply_with_currency_name('Netflix cuesta 30.000 pesos.', 'COP', $name), 'a reply that already names it is untouched');
    assert_same('SCOPE COPA', ai_reply_with_currency_name('SCOPE COPA', 'COP', $name), 'the code inside other words is untouched');
    assert_same('COP 10', ai_reply_with_currency_name('COP 10', 'COP', 'COP'), 'with no real name the reply is unchanged');
});
