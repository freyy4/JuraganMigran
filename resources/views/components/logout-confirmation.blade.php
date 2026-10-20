<dialog id="logout-confirmation" class="logout-confirmation" aria-labelledby="logout-confirmation-title" aria-describedby="logout-confirmation-description">
    <div class="logout-confirmation-icon" aria-hidden="true"><x-ui-icon name="shield"/></div>
    <h2 id="logout-confirmation-title">Apakah Anda yakin untuk Logout?</h2>
    <p id="logout-confirmation-description">Anda akan keluar dari akun pada perangkat ini.</p>
    <div class="logout-confirmation-actions">
        <button type="button" class="secondary" data-logout-cancel autofocus>Batal</button>
        <button type="button" class="logout-confirm-button" data-logout-confirm>Ya, Logout</button>
    </div>
</dialog>
<script src="{{ asset('js/logout-confirmation.js').'?v='.filemtime(public_path('js/logout-confirmation.js')) }}" defer></script>
