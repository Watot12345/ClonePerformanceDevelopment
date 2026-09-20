/**
 * Oxford Suites, Makati — Reusable UI Components & View Helpers
 * Clean, portable component generators for consistent UI rendering across modules.
 */

window.UIComponents = (function() {
    'use strict';

    return {
        /**
         * Render a semantic status pill badge
         * @param {string} label 
         * @param {'sage'|'dusty'|'gold'|'primary'|'rose'|'indigo'|'slate'} variant 
         * @param {string} [icon] FontAwesome icon class (e.g. 'fa-check')
         */
        badge: function(label, variant = 'primary', icon = '') {
            const iconHtml = icon ? `<i class="fas ${icon} text-[9px] mr-1"></i>` : '';
            return `<span class="badge-${variant} inline-flex items-center shadow-2xs">${iconHtml}${label}</span>`;
        },

        /**
         * Render an empty state placeholder
         */
        emptyState: function({ icon = 'fa-bullseye', title = 'No Records Found', description = '', actionHtml = '' }) {
            return `
                <div class="col-span-full p-6 bg-slate-50/80 rounded-2xl border border-dashed border-slate-200 text-center space-y-2">
                    <div class="w-10 h-10 rounded-full bg-primary-50 text-primary flex items-center justify-center text-base mx-auto">
                        <i class="fas ${icon}"></i>
                    </div>
                    <h4 class="font-bold text-slate-800 text-xs">${title}</h4>
                    ${description ? `<p class="text-[11px] text-slate-500 max-w-sm mx-auto">${description}</p>` : ''}
                    ${actionHtml ? `<div class="pt-1">${actionHtml}</div>` : ''}
                </div>
            `;
        },

        /**
         * Render animated skeleton rows for table bodies
         */
        tableSkeletonRows: function(rows = 3, cols = 4) {
            let html = '';
            for (let r = 0; r < rows; r++) {
                html += `
                    <tr class="animate-pulse border-b border-slate-100 text-xs">
                        <td class="px-5 py-3.5"><div class="space-y-1"><div class="h-3.5 bg-slate-200 rounded w-36"></div><div class="h-2.5 bg-slate-100 rounded w-20"></div></div></td>
                        <td class="px-5 py-3.5"><div class="h-3 bg-slate-100 rounded w-48"></div></td>
                        <td class="px-5 py-3.5"><div class="h-3 bg-slate-200 rounded w-16"></div></td>
                        ${cols >= 4 ? `<td class="px-5 py-3.5"><div class="h-4 bg-slate-100 rounded-full w-20"></div></td>` : ''}
                        ${cols >= 5 ? `<td class="px-5 py-3.5 text-right"><div class="h-6 bg-slate-200 rounded-lg w-16 ml-auto"></div></td>` : ''}
                    </tr>
                `;
            }
            return html;
        }
    };
})();
