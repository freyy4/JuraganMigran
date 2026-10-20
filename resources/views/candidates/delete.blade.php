<form action="{{ route('candidates.destroy', $candidate) }}" method="post" onsubmit="return confirm('Hapus calon pekerja ini dari daftar?')">
    @csrf @method('DELETE')
    <button class="danger" type="submit" aria-label="Hapus {{ $candidate->person?->full_name }}">Hapus</button>
</form>
