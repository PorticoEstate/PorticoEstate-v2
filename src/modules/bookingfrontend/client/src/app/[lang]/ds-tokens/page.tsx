'use client';
import React, { useEffect, useState } from 'react';

interface TokenValue {
    name: string;
    computed: string;
}

interface TokenGroup {
    category: string;
    tokens: TokenValue[];
}

const PREFIXES = [
    'color-neutral',
    'color-accent',
    'color-brand1',
    'color-brand2',
    'color-brand3',
    'color-danger',
    'color-warning',
    'color-info',
    'color-success',
    'color-main',
    'color-focus',
    'color-text',
    'color-border',
    'color-surface',
    'color-background',
    'color-base',
    'global-blue',
    'global-green',
    'global-red',
    'global-yellow',
    'global-orange',
    'global-purple',
    'font-size',
    'font-weight',
    'spacing',
    'sizing',
    'size',
    'border-radius',
    'radius',
    'shadow',
    'opacity',
    'line-height',
    'border-width',
];

export default function DsTokensPage() {
    const [groups, setGroups] = useState<TokenGroup[]>([]);
    const [filter, setFilter] = useState('');

    useEffect(() => {
        const root = document.documentElement;
        const cs = getComputedStyle(root);

        // Collect all --ds-* variables from all stylesheets
        const allVars = new Set<string>();
        for (const sheet of document.styleSheets) {
            try {
                for (const rule of sheet.cssRules) {
                    const text = rule.cssText;
                    const matches = text.matchAll(/--ds-[a-zA-Z0-9-]+/g);
                    for (const m of matches) {
                        allVars.add(m[0]);
                    }
                }
            } catch {
                // cross-origin stylesheets
            }
        }

        // Group by prefix
        const groupMap = new Map<string, TokenValue[]>();

        const sorted = [...allVars].sort();
        for (const name of sorted) {
            const raw = cs.getPropertyValue(name).trim();
            if (!raw) continue;

            // Only resolve color tokens via DOM — skip non-color tokens
            let computed = raw;
            const varSuffix = name.replace('--ds-', '');
            const isLikelyColor = varSuffix.startsWith('color-') || varSuffix.startsWith('global-');
            if (isLikelyColor) {
                try {
                    const el = document.createElement('div');
                    el.style.color = `var(${name})`;
                    document.body.appendChild(el);
                    computed = getComputedStyle(el).color;
                    document.body.removeChild(el);
                } catch {
                    // fallback
                }
            }

            // Find best category
            const stripped = name.replace('--ds-', '');
            let category = 'other';
            for (const p of PREFIXES) {
                if (stripped.startsWith(p)) {
                    category = p;
                    break;
                }
            }

            if (!groupMap.has(category)) groupMap.set(category, []);
            groupMap.get(category)!.push({ name, computed });
        }

        setGroups(
            [...groupMap.entries()]
                .sort(([a], [b]) => a.localeCompare(b))
                .map(([category, tokens]) => ({ category, tokens }))
        );
    }, []);

    const filtered = filter
        ? groups
            .map((g) => ({
                ...g,
                tokens: g.tokens.filter(
                    (t) =>
                        t.name.toLowerCase().includes(filter.toLowerCase()) ||
                        t.computed.toLowerCase().includes(filter.toLowerCase())
                ),
            }))
            .filter((g) => g.tokens.length > 0)
        : groups;

    const isColor = (val: string) =>
        val.startsWith('rgb') || val.startsWith('#') || val.startsWith('hsl');

    const rgbToHex = (rgb: string): string => {
        const match = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
        if (!match) return rgb;
        const [, r, g, b] = match;
        return (
            '#' +
            [r, g, b].map((x) => parseInt(x).toString(16).padStart(2, '0')).join('')
        );
    };

    return (
        <div style={{ padding: '24px 32px', fontFamily: 'Inter, system-ui, sans-serif', maxWidth: 1200 }}>
            <h1 style={{ fontSize: 24, marginBottom: 8 }}>Digdir Designsystemet Tokens</h1>
            <p style={{ color: '#666', marginBottom: 16 }}>
                {groups.reduce((n, g) => n + g.tokens.length, 0)} tokens found.
                Showing computed values from the current theme.
            </p>
            <input
                type="text"
                placeholder="Filter by name or value (e.g. #793, neutral, border)..."
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
                style={{
                    width: '100%',
                    maxWidth: 500,
                    padding: '8px 12px',
                    fontSize: 14,
                    border: '1px solid #ccc',
                    borderRadius: 6,
                    marginBottom: 24,
                }}
            />

            {filtered.map((group) => (
                <div key={group.category} style={{ marginBottom: 32 }}>
                    <h2
                        style={{
                            fontSize: 14,
                            textTransform: 'uppercase',
                            letterSpacing: '0.05em',
                            color: '#666',
                            borderBottom: '1px solid #e0e0e0',
                            paddingBottom: 6,
                            marginBottom: 12,
                        }}
                    >
                        {group.category} ({group.tokens.length})
                    </h2>
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(auto-fill, minmax(340px, 1fr))',
                            gap: 8,
                        }}
                    >
                        {group.tokens.map((token) => {
                            const color = isColor(token.computed);
                            const hex = color ? rgbToHex(token.computed) : '';
                            const isBorderColor = token.name.includes('border') && color;
                            const isBorderWidth = token.name.includes('border-width');
                            const isRadius = token.name.includes('radius');
                            const isFontSize = token.name.includes('font-size');
                            const isFontWeight = token.name.includes('font-weight');
                            const isFont = isFontSize || isFontWeight;

                            const preview = (() => {
                                if (isBorderColor) {
                                    return (
                                        <div
                                            style={{
                                                width: '2rem',
                                                height: '2rem',
                                                borderRadius: 4,
                                                backgroundColor: 'var(--ds-color-accent-background-tinted)',
                                                border: `2px solid ${token.computed}`,
                                                flexShrink: 0,
                                            }}
                                        />
                                    );
                                }
                                if (isBorderWidth) {
                                    return (
                                        <div
                                            style={{
                                                width: '2rem',
                                                height: '2rem',
                                                borderRadius: 4,
                                                backgroundColor: 'var(--ds-color-accent-background-tinted)',
                                                border: `var(${token.name}) solid var(--ds-color-border-default)`,
                                                flexShrink: 0,
                                            }}
                                        />
                                    );
                                }
                                if (isRadius) {
                                    return (
                                        <div
                                            style={{
                                                width: '2rem',
                                                height: '2rem',
                                                backgroundColor: 'var(--ds-color-accent-base-default)',
                                                borderRadius: `var(${token.name})`,
                                                flexShrink: 0,
                                            }}
                                        />
                                    );
                                }
                                if (isFontSize) {
                                    return (
                                        <span
                                            style={{
                                                fontSize: `var(${token.name})`,
                                                fontWeight: 500,
                                                lineHeight: 1.2,
                                                flexShrink: 0,
                                                color: '#333',
                                            }}
                                        >
                                            Abc
                                        </span>
                                    );
                                }
                                if (isFontWeight) {
                                    return (
                                        <span
                                            style={{
                                                fontSize: 14,
                                                fontWeight: `var(${token.name})` as any,
                                                lineHeight: 1.2,
                                                flexShrink: 0,
                                                color: '#333',
                                            }}
                                        >
                                            Abc
                                        </span>
                                    );
                                }
                                if (color) {
                                    return (
                                        <div
                                            style={{
                                                width: 28,
                                                height: 28,
                                                borderRadius: 4,
                                                backgroundColor: token.computed,
                                                border: '1px solid #ddd',
                                                flexShrink: 0,
                                            }}
                                        />
                                    );
                                }
                                return null;
                            })();

                            return (
                                <div
                                    key={token.name}
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 10,
                                        padding: '6px 10px',
                                        borderRadius: 6,
                                        background: '#fafafa',
                                        border: '1px solid #eee',
                                        minHeight: 44,
                                    }}
                                >
                                    {preview}
                                    <div style={{ minWidth: 0, flex: 1 }}>
                                        <div
                                            style={{
                                                fontSize: 12,
                                                fontWeight: 500,
                                                fontFamily: 'monospace',
                                                whiteSpace: 'nowrap',
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                            }}
                                        >
                                            {token.name}
                                        </div>
                                        <div style={{ fontSize: 11, color: '#888', fontFamily: 'monospace' }}>
                                            {color ? hex : token.computed}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}
