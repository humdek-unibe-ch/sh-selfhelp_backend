/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */
/**
 * Reference source for the QA live test plugin runtime. E2e loads
 * `dist/plugin.esm.js` (hand-written ESM); keep behaviour in sync.
 */

import { useQuery } from '@tanstack/react-query';
import { definePlugin } from '@selfhelp/shared/plugin-sdk';
import type { IPluginRegistration, IPluginStyleProps } from '@selfhelp/shared/plugin-sdk';

const PLUGIN_ID = 'sh2-qa-live';
const PLUGIN_VERSION = '1.0.0';
const API = `/api/admin/plugins/${PLUGIN_ID}`;

export function QaLiveItemStyle({ style, cssClass }: IPluginStyleProps) {
    const plugin = (style.plugin ?? {}) as { title?: string; version?: number; not_found?: boolean };
    if (plugin.not_found) {
        return (
            <div className={cssClass} data-testid="qa-live-item">
                not found
            </div>
        );
    }

    return (
        <div className={cssClass} data-testid="qa-live-item" data-version={plugin.version ?? ''}>
            {plugin.title ?? ''}
        </div>
    );
}

async function fetchItems(): Promise<Array<{ id: number; title: string; published_version: number }>> {
    const res = await fetch(`${API}/items`, { credentials: 'include' });
    const body = (await res.json()) as { data?: { items?: Array<{ id: number; title: string; published_version: number }> } };
    if (!res.ok) {
        throw new Error('Failed to load items');
    }

    return body.data?.items ?? [];
}

export function QaLiveItemsAdminPage() {
    const query = useQuery({
        queryKey: ['plugins', PLUGIN_ID, 'items'],
        queryFn: fetchItems,
        // Role grants bump X-Acl-Stamp / push `acl`; keep the open list honest even
        // when the host's sync map refetch is delayed by the acl SSE reconnect.
        refetchInterval: (q) => ((q.state.data ?? []).length === 0 ? 750 : false),
        refetchIntervalInBackground: false,
    });

    if (query.isLoading) {
        return <p>Loading…</p>;
    }
    if (query.isError) {
        return <p data-testid="qa-live-items-error">Failed to load items</p>;
    }

    return (
        <ul>
            {(query.data ?? []).map((row) => (
                <li key={row.id} data-testid="qa-live-item-row" data-id={row.id}>
                    {row.title}
                </li>
            ))}
        </ul>
    );
}

export const register = (_api: unknown): IPluginRegistration =>
    definePlugin({
        id: PLUGIN_ID,
        version: PLUGIN_VERSION,
        pluginApiVersion: '0.2.0',
        styles: [
            {
                name: 'qa-live-item',
                description: 'QA live item renderer',
                category: 'plugin',
                canHaveChildren: false,
                component: QaLiveItemStyle as never,
            },
        ],
        adminPages: [
            {
                slug: 'items',
                title: 'QA Live items',
                permission: 'qa_live.items.read',
                component: QaLiveItemsAdminPage as never,
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