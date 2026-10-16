<?php
script('retention_lock', 'admin');
?>
<section class="section" id="retention-lock-admin">
    <h2>Retained files</h2>
    <p>Early purge permanently removes a deleted item and its retained versions. A reason is required and recorded.</p>
    <form id="retention-list-form">
        <label for="retention-owner">Nextcloud username</label>
        <input id="retention-owner" name="ownerUid" required maxlength="64" autocomplete="off">
        <button type="submit">Load deleted items</button>
    </form>
    <form id="retention-purge-form" hidden>
        <label for="retention-item">Deleted item</label>
        <select id="retention-item" required></select>
        <label for="retention-reason">Reason for permanent deletion</label>
        <textarea id="retention-reason" required maxlength="2000"></textarea>
        <label><input id="retention-confirm" type="checkbox" required> Permanently delete this item and its versions</label>
        <button type="submit">Purge permanently</button>
    </form>
    <p id="retention-result" role="status" aria-live="polite"></p>
    <h3>Inspect a purge operation</h3>
    <p>Pending or uncertain operations require inspection. Looking up an operation does not retry deletion.</p>
    <form id="retention-operation-form">
        <label for="retention-operation">Operation ID</label>
        <input id="retention-operation" required maxlength="36" autocomplete="off">
        <button type="submit">Inspect operation</button>
    </form>
    <pre id="retention-operation-result" role="status" aria-live="polite"></pre>
</section>
