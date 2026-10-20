<div class="contact" data-contact>
    <div class="contact-heading"><strong>Kontak keluarga / darurat</strong><button type="button" class="danger" data-remove-contact>Hapus kontak</button></div>
    <div class="grid">
    @foreach(['name'=>'Nama kontak','relationship'=>'Hubungan','phone'=>'Telepon','whatsapp'=>'WhatsApp'] as $field=>$label)
        <x-candidate-field :name="'contacts['.$index.']['.$field.']'" :label="$label" :value="$contact[$field] ?? null" :required="$field === 'name'" :maxlength="in_array($field, ['phone','whatsapp']) ? 30 : 255" />
    @endforeach
    <div><label for="contact-type-{{ $index }}">Jenis kontak</label><select id="contact-type-{{ $index }}" name="contacts[{{ $index }}][contact_type]"><option value="family" @selected(($contact['contact_type'] ?? 'family') === 'family')>Keluarga</option><option value="emergency" @selected(($contact['contact_type'] ?? '') === 'emergency')>Darurat</option></select></div>
    <div><label for="contact-address-{{ $index }}">Alamat kontak</label><textarea id="contact-address-{{ $index }}" name="contacts[{{ $index }}][address]" maxlength="5000">{{ $contact['address'] ?? '' }}</textarea></div>
    @foreach(['is_emergency'=>'Kontak darurat','is_primary'=>'Kontak utama'] as $field=>$label)
    <div><input type="hidden" name="contacts[{{ $index }}][{{ $field }}]" value="0"><label class="check"><input type="checkbox" name="contacts[{{ $index }}][{{ $field }}]" value="1" @checked($contact[$field] ?? false)>{{ $label }}</label></div>
    @endforeach
    </div>
</div>
