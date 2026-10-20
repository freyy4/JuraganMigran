@props(['state'=>'unknown','label'=>null])
<x-status-badge :value="$state" :label="$label??['overdue'=>'Overdue','warning'=>'Mendekati SLA','normal'=>'On Track','unknown'=>'Belum tercatat'][$state]??$state" :tone="['overdue'=>'red','warning'=>'amber','normal'=>'green','unknown'=>'slate'][$state]??'slate'"/>
