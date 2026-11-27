/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */
/**
 * Hand-written ESM runtime for sh2-qa-live (e2e source of truth).
 */

import React, { createElement as h } from 'react';
import { useQuery } from '@tanstack/react-query';
import { definePlugin } from '@selfhelp/shared/plugin-sdk';

const PLUGIN_ID = 'sh2-qa-live';
const PLUGIN_VERSION = '1.0.0';
const API = `/api/admin/plugins/${PLUGIN_ID}`;

function QaLiveItemStyle({ style, cssClass }) {
    const plugin = style?.plugin ?? {};
    if (plugin.not_found) {
        return h('div', { className: cssClass, 'data-testid': 'qa-live-item' }, 'not found');
    }

    return h(
        'div',
        {
            className: cssClass,
            'data-testid': 'qa-live-item',
            'data-version': plugin.version ?? '',
        },
        plugin.title ?? '',
    );
}

async function fetchItems() {
    const res = await fetch(`${API}/items`, { credentials: 'include' });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(body?.error ?? `HTTP ${res.status}`);
    }

    return body?.data?.items ?? [];
}

function QaLiveItemsAdminPage() {
    const query = useQuery({
        queryKey: ['plugins', PLUGIN_ID, 'items'],
        queryFn: fetchItems,
        // Role grants bump X-Acl-Stamp / push `acl`; keep the open list honest even
        // when the host's sync map refetch is delayed by the acl SSE reconnect.
        refetchInterval: (q) => ((q.state.data ?? []).length === 0 ? 750 : false),
        refetchIntervalInBackground: false,
    });

    if (query.isLoading) {
        return h('p', null, 'Loading…');
    }
    if (query.isError) {
        return h('p', { 'data-testid': 'qa-live-items-error' }, 'Failed to load items');
    }

    const rows = Array.isArray(query.data) ? query.data : [];
    return h(
        'ul',
        null,
        ...rows.map((row) =>
            h(
                'li',
                {
                    key: row.id,
                    'data-testid': 'qa-live-item-row',
                    'data-id': row.id,
                },
                row.title ?? '',
            ),
        ),
    );
}

export function register(_api) {
    return definePlugin({
        id: PLUGIN_ID,
        version: PLUGIN_VERSION,
        pluginApiVersion: '0.2.0',
        styles: [
            {
                name: 'qa-live-item',
                description: 'QA live item renderer',
                category: 'plugin',
                canHaveChildren: false,
                component: QaLiveItemStyle,
            },
        ],
        adminPages: [
            {
                slug: 'items',
                title: 'QA Live items',
                permission: 'qa_live.items.read',
                component: QaLiveItemsAdminPage,
            },
        ],
        sync: [
            {
                tags: ['acl'],
                queryKey: ['plugins', PLUGIN_ID, 'items'],
            },
            {
                tags: ['res:qa_live_item:*'],
                queryKey: ['plugins', PLUGIN_ID, 'items'],
            },
        ],
    });
}