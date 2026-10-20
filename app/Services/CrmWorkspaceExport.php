<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class CrmWorkspaceExport
{
    public function create(Request $request, array $ids, string $format, bool $contacts, string $jobId): string
    {
        abort_if($contacts && ! $request->user()->hasPermission('lead.read.contact'), 403);
        $rows = app(ConversationCrm::class)->leads($request, 'lead.export')->whereIn('id', $ids)->whereNull('archived_at')->orderBy('id')->cursor();
        $path = 'crm-exports/'.$jobId.'.'.$format;
        Storage::disk('local')->makeDirectory('crm-exports');
        $absolute = Storage::disk('local')->path($path);
        $headers = ['Lead ID', 'Nama', 'Sumber', 'Tahap', 'PIC ID', 'Follow-up', 'Dibuat', 'Diperbarui'];
        if ($contacts) {
            $headers = array_merge($headers, ['WhatsApp', 'Email']);
        }
        $file = fopen($format === 'csv' ? $absolute : $absolute.'.xml', 'wb');
        try {
            if ($format === 'csv') {
                fwrite($file, "\xEF\xBB\xBF");
                fputcsv($file, $headers, ',', '"', '');
            } else {
                fwrite($file, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
                $this->xmlRow($file, $headers);
            }
            foreach ($rows as $row) {
                $values = [$row->id, $row->name, $row->crm_source, config('conversation.stages.'.$row->crm_stage), $row->assigned_cs_id, $row->next_followup_at, $row->created_at, $row->updated_at];
                if ($contacts) {
                    $values = array_merge($values, [$row->normalized_phone, $row->email]);
                }
                $values = array_map(function ($value): string {
                    $text = (string) ($value ?? '');

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) ? "'".$text : $text;
                }, $values);
                if ($format === 'csv') {
                    fputcsv($file, $values, ',', '"', '');
                } else {
                    $this->xmlRow($file, $values);
                }
            }
            if ($format === 'xlsx') {
                fwrite($file, '</sheetData></worksheet>');
            }
        } finally {
            fclose($file);
        }
        if ($format === 'xlsx') {
            $zip = new ZipArchive;
            abort_unless($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Pipeline Lead" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFile($absolute.'.xml', 'xl/worksheets/sheet1.xml');
            $zip->close();
            unlink($absolute.'.xml');
        }

        return $path;
    }

    /** @param resource $file */
    private function xmlRow($file, array $values): void
    {
        fwrite($file, '<row>');
        foreach ($values as $value) {
            $text = htmlspecialchars(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', '', (string) $value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            fwrite($file, '<c t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>');
        }
        fwrite($file, '</row>');
    }
}
