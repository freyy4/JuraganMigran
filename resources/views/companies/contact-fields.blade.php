<div class="contact" data-contact>
<div class="contact-heading"><strong>Penanggung jawab (PIC)</strong><button type="button" class="danger" data-remove-contact>Hapus PIC</button></div>
@if(!empty($contact['id']))<input type="hidden" name="contacts[{{ $index }}][id]" value="{{ $contact['id'] }}">@endif
<div class="grid">
@foreach(['name'=>'Nama PIC','position'=>'Jabatan','phone'=>'Telepon','whatsapp'=>'WhatsApp','email'=>'Email'] as $field=>$label)
<x-candidate-field :name="'contacts['.$index.']['.$field.']'" :label="$label" :value="$contact[$field] ?? null" :required="$field === 'name'" :type="match($field) {'email'=>'email','phone','whatsapp'=>'tel',default=>'text'}" :maxlength="in_array($field, ['phone','whatsapp']) ? 30 : 255" />
@endforeach
<div><input type="hidden" name="contacts[{{ $index }}][is_primary]" value="0"><label class="check"><input type="checkbox" name="contacts[{{ $index }}][is_primary]" value="1" @checked($contact['is_primary'] ?? false)>PIC utama</label></div>
</div></div>
