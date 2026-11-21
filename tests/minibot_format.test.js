// Run with: node --test tests/minibot_format.test.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { parseMinibotReply } = require('../scripts/minibot-format.js');

test('plain text becomes one paragraph, line breaks kept', () => {
  assert.deepEqual(parseMinibotReply('Hola\nPagas 30.000 pesos.'), [
    { type: 'p', lines: [[{ text: 'Hola', bold: false }], [{ text: 'Pagas 30.000 pesos.', bold: false }]] },
  ]);
});

test('bold segments and blank-line paragraphs', () => {
  const blocks = parseMinibotReply('Tu más cara es **Netflix**.\n\nNada más.');
  assert.equal(blocks.length, 2);
  assert.deepEqual(blocks[0].lines[0], [
    { text: 'Tu más cara es ', bold: false },
    { text: 'Netflix', bold: true },
    { text: '.', bold: false },
  ]);
});

test('bullet and numbered lists', () => {
  const blocks = parseMinibotReply('Opciones:\n- Netflix\n* Spotify\n1. Primera\n2) Segunda');
  assert.deepEqual(blocks.map((block) => block.type), ['p', 'ul', 'ol']);
  assert.equal(blocks[1].items.length, 2);
  assert.equal(blocks[2].items[1][0].text, 'Segunda');
});

test('HTML stays text and headings turn into bold lines', () => {
  const blocks = parseMinibotReply('## Resumen\n<img src=x onerror=alert(1)>');
  assert.deepEqual(blocks[0].lines[0], [{ text: 'Resumen', bold: true }]);
  assert.equal(blocks[0].lines[1][0].text, '<img src=x onerror=alert(1)>');
});

test('empty or missing replies give no blocks', () => {
  assert.deepEqual(parseMinibotReply(''), []);
  assert.deepEqual(parseMinibotReply(null), []);
});
