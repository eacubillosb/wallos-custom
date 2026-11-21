// Dia's replies use a small slice of Markdown: **bold**, "- " or "1. " lists
// and blank-line paragraphs. This turns a reply into plain blocks; minibot.js
// builds DOM nodes from them with textContent, so no reply text is ever
// parsed as HTML.
//
// Result: [{ type: "p", lines: [segments, ...] },
//          { type: "ul" | "ol", items: [segments, ...] }]
// where segments is [{ text, bold }].

function parseInlineBold(line) {
  const segments = [];
  const pattern = /\*\*(.+?)\*\*/g;
  let last = 0;
  let match;
  while ((match = pattern.exec(line)) !== null) {
    if (match.index > last) {
      segments.push({ text: line.slice(last, match.index), bold: false });
    }
    segments.push({ text: match[1], bold: true });
    last = pattern.lastIndex;
  }
  if (last < line.length) {
    segments.push({ text: line.slice(last), bold: false });
  }
  return segments;
}

function parseMinibotReply(text) {
  const blocks = [];
  let current = null;

  String(text || "")
    .replace(/\r\n?/g, "\n")
    .split("\n")
    .forEach((rawLine) => {
      const line = rawLine.trim();
      if (line === "") {
        current = null;
        return;
      }

      const bullet = line.match(/^[-*•]\s+(.*)$/);
      const numbered = line.match(/^\d+[.)]\s+(.*)$/);
      const listType = bullet ? "ul" : numbered ? "ol" : null;

      if (listType) {
        if (!current || current.type !== listType) {
          current = { type: listType, items: [] };
          blocks.push(current);
        }
        current.items.push(parseInlineBold((bullet || numbered)[1]));
        return;
      }

      // Headings are not wanted; keep their text as an ordinary bold line.
      const heading = line.match(/^#{1,6}\s+(.*)$/);
      const segments = heading ? [{ text: heading[1], bold: true }] : parseInlineBold(line);
      if (!current || current.type !== "p") {
        current = { type: "p", lines: [] };
        blocks.push(current);
      }
      current.lines.push(segments);
    });

  return blocks;
}

if (typeof module !== "undefined" && module.exports) {
  module.exports = { parseMinibotReply, parseInlineBold };
}
