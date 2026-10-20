@extends('errors.layout')

@section('error-code', '413')
@section('error-title', 'הקובץ גדול מדי')
@section('error-description', 'הבקשה חורגת ממגבלת ההעלאה של השרת. יש לבחור תמונת JPG, PNG או WebP בגודל של עד 2MB ולנסות שוב.')
