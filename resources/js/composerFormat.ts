import { JSONContent } from '@tiptap/core';

/*
 * Messages are stored as plain text with marks: *bold* _italic_ __underline__ ~strike~ `code`.
 * The editor shows them as live formatting; these functions convert between the two.
 */

const WRAPPERS: Record<string, string> = { bold: '*', italic: '_', underline: '__', strike: '~', code: '`' };

function markText(node: JSONContent): string {
    const text = node.text ?? '';
    const marks = (node.marks ?? []).map((mark) => mark.type).filter((type) => type in WRAPPERS);

    if (marks.length === 0 || text.trim() === '') return text;

    // Marks must touch the words, or they would not be read back as formatting.
    const [, before, core, after] = text.match(/^(\s*)([\s\S]*?)(\s*)$/) as RegExpMatchArray;
    const wrapped = marks.reduce((inner, type) => `${WRAPPERS[type]}${inner}${WRAPPERS[type]}`, core);

    return `${before}${wrapped}${after}`;
}

function inline(nodes: JSONContent[] = []): string {
    return nodes.map((node) => (node.type === 'hardBreak' ? '\n' : markText(node))).join('');
}

function block(node: JSONContent): string[] {
    const children = node.content ?? [];

    switch (node.type) {
        case 'paragraph':
            return inline(children).split('\n');
        case 'bulletList':
            return children.flatMap((item) => (item.content ?? []).flatMap(block).map((line) => `- ${line}`));
        case 'orderedList':
            return children.flatMap((item, index) =>
                (item.content ?? []).flatMap(block).map((line) => `${index + 1}. ${line}`),
            );
        case 'blockquote':
            return children.flatMap(block).map((line) => `> ${line}`);
        case 'codeBlock':
            return ['```', ...children.map((child) => child.text ?? '').join('').split('\n'), '```'];
        default:
            return children.flatMap(block);
    }
}

export function serialize(doc: JSONContent): string {
    return (doc.content ?? []).flatMap(block).join('\n').trim();
}

/** Drafts are stored as text, so they open with their marks visible and still send correctly. */
export function textToDoc(text: string): JSONContent {
    return {
        type: 'doc',
        content: text.split('\n').map((line) => ({
            type: 'paragraph',
            ...(line ? { content: [{ type: 'text', text: line }] } : {}),
        })),
    };
}
