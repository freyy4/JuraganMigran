@props(['value'=>'normal'])
<x-status-badge :value="$value" :label="['critical'=>'Kritis','urgent'=>'Mendesak','high'=>'Tinggi','normal'=>'Normal','low'=>'Rendah'][$value]??$value" :tone="in_array($value,['critical','urgent','high'])?'red':($value==='low'?'slate':'amber')"/>
