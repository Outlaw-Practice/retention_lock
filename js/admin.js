'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('retention-lock-admin');
    if (!root) return;
    const byId = (id) => root.querySelector('#retention-' + id);
    const result = byId('result');
    const purgeForm = byId('purge-form');
    let selection = null;
    let busy = false;

    async function request(endpoint, payload) {
        const response = await fetch(OC.generateUrl('/apps/retention_lock/' + endpoint), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
            body: JSON.stringify(payload),
        });
        const body = await response.json();
        if (!response.ok) throw new Error(body.error || 'Operation requires administrator inspection.');
        return body;
    }

    byId('list-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        selection = null;
        purgeForm.hidden = true;
        byId('confirm').checked = false;
        byId('reason').value = '';
        result.textContent = 'Loading deleted items';
        const ownerUid = byId('owner').value;
        try {
            const { items } = await request('trash', { ownerUid });
            byId('item').replaceChildren();
            for (const [index, item] of items.entries()) {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = item.path + ' (deleted ' + new Date(item.deletedAt * 1000).toLocaleString() + ')';
                byId('item').append(option);
            }
            selection = { ownerUid, items };
            purgeForm.hidden = items.length === 0;
            result.textContent = items.length ? 'Select an item to purge.' : 'No deleted items found.';
        } catch (error) {
            result.textContent = 'Unable to load deleted items. Check the username and try again.';
        } finally { busy = false; }
    });

    byId('item').addEventListener('change', () => { byId('confirm').checked = false; });
    byId('owner').addEventListener('input', () => {
        selection = null;
        purgeForm.hidden = true;
    });

    purgeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !selection || selection.ownerUid !== byId('owner').value) return;
        const item = selection.items[Number(byId('item').value)];
        if (!item || !byId('confirm').checked || !byId('reason').value.trim()) return;
        let operationId;
        try {
            operationId = crypto.randomUUID();
        } catch (error) {
            result.textContent = 'Cannot create an operation ID. Use a supported browser over HTTPS.';
            return;
        }
        busy = true;
        byId('operation').value = operationId;
        const payload = { operationId, ownerUid: selection.ownerUid,
            trashId: item.trashId, deletedAt: item.deletedAt, expectedEtag: item.expectedEtag,
            reason: byId('reason').value };
        // A failed response may follow a committed intent or partial deletion.
        // Always discard the selection; never issue an automatic new operation.
        selection = null;
        purgeForm.hidden = true;
        result.textContent = 'Purge requested. Operation ID: ' + operationId;
        try {
            await request('purges', payload);
            result.textContent = 'Purge completed. Operation ID: ' + operationId;
        } catch (error) {
            result.textContent = 'Purge completion was not confirmed. Inspect operation ' + operationId + ' before taking further action.';
        } finally { busy = false; }
    });

    byId('operation-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const output = byId('operation-result');
        output.textContent = 'Loading operation';
        try {
            const record = await request('operations', { operationId: byId('operation').value.trim() });
            output.textContent = JSON.stringify(record, null, 2);
        } catch (error) {
            output.textContent = 'Operation unavailable. This does not establish whether deletion occurred; check the service before retrying.';
        }
    });
});
