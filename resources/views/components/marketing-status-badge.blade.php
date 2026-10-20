@props(['value'=>'','label'=>null])
@php($tone=match(strtolower($value)){'published','active','completed'=>'green','review','paused'=>'amber','archived','closed'=>'red',default=>'blue'})
<x-status-badge :value="$value" :label="$label" :tone="$tone"/>
