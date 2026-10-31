<?php

namespace App\Http\Controllers\Club;

use App\Core\Papers\Paper;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperSize;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every document's paper, whatever its type: the print page (the paper with the browser's print dialog) and the PDF.
 *
 * The record is looked up inside the club (tenant scope), must be in a branch the user reaches (else 404, as if it did
 * not exist), and the user needs that document's own permission (else 403) — the same checks for every format, so
 * a payslip is no easier to reach as a PDF than on screen. Printing it (the print dialog) and the PDF also need
 * papers.print; reading the paper on screen does not.
 */
class PaperController extends Controller
{
    public function show(Request $request, string $type, int $id): Response
    {
        $paper = $this->paper($request, $type, $id);
        abort_if($request->boolean('print') && ! $request->user()->can('papers.print'), 403, 'الطباعة تحتاج صلاحية «طباعة المستندات».');

        return $paper->page($request->boolean('print'));
    }

    public function pdf(Request $request, string $type, int $id): Response
    {
        $paper = $this->paper($request, $type, $id);
        abort_unless($request->user()->can('papers.print'), 403, 'ملف PDF يحتاج صلاحية «طباعة المستندات».');

        return $paper->pdf($request->boolean('download'));
    }

    private function paper(Request $request, string $type, int $id): Paper
    {
        $builder = Papers::builder($type);
        $record = $builder::find($id);
        abort_if($record === null || ! Papers::reachable($request->user(), $record), 404);
        abort_unless($builder::allows($request->user(), $record), 403);
        $size = $request->string('size')->toString();

        return Papers::for($type, $record, null, $request->string('face')->toString() === 'strip' ? 'strip' : 'sheet', isset(PaperSize::PRESETS[$size]) ? $size : null);
    }
}
