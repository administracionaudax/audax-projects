<?php

use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\Weeklies\AiUsageController;
use App\Http\Controllers\Weeklies\AssistantController;
use App\Http\Controllers\Weeklies\ClientInsightsController;
use App\Http\Controllers\Weeklies\DictationController;
use App\Http\Controllers\Weeklies\HelpContentController;
use App\Http\Controllers\Weeklies\HelpController;
use App\Http\Controllers\Weeklies\MySpaceController;
use App\Http\Controllers\Weeklies\MySpaceTaskController;
use App\Http\Controllers\Weeklies\MyWeeklyController;
use App\Http\Controllers\Weeklies\ProjectStatusController;
use App\Http\Controllers\Weeklies\SuggestionBoardController;
use App\Http\Controllers\Weeklies\SuggestionCommentController;
use App\Http\Controllers\Weeklies\SuggestionController;
use App\Http\Controllers\Weeklies\TeamController;
use App\Http\Controllers\Weeklies\WeeklyAudioController;
use App\Http\Controllers\Weeklies\WeeklyClientSubscriptionController;
use App\Http\Controllers\Weeklies\WeeklyCycleController;
use App\Http\Controllers\Weeklies\WeeklyExemptionController;
use App\Http\Controllers\Weeklies\WeeklyReminderController;
use App\Http\Controllers\Weeklies\WeeklyReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| La Weekly (Fase 10, D-145 a D-150; contrato 10.1)
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo interno ['auth', 'active', 'internal',
| 'collaborator', '2fa']: un colaborador externo no entra en ninguna (D-134, no están en
| config/collaborators.php) y un cliente va a su portal.
| URLs en español y nombres de ruta en inglés. Cada módulo se puede apagar en los ajustes
| (`module:…`, F-177): sus rutas dan entonces 404.
| La autorización va en cada controlador (políticas WeeklyCycle, WeeklySubmission, WeeklyExemption,
| Dictation, SuggestionPost y SuggestionComment; gates use-weeklies, manage-weeklies, manage-help,
| view-ai-usage y view-person-ai-summary). Las acciones que aún no existen responden 501 tras
| autorizar; la entrega que las completa está en cada controlador.
*/

Route::middleware('module:weeklies')->group(function () {
    // Histórico y semanas (10.2) e informe, audio y cierre (10.3).
    Route::get('weeklies', [WeeklyCycleController::class, 'index'])->name('weeklies.index');
    Route::post('weeklies', [WeeklyCycleController::class, 'store'])->name('weeklies.store');
    Route::get('weeklies/{cycle}', [WeeklyCycleController::class, 'show'])->whereNumber('cycle')->name('weeklies.show');
    Route::delete('weeklies/{cycle}', [WeeklyCycleController::class, 'destroy'])->whereNumber('cycle')->name('weeklies.destroy');
    Route::put('weeklies/{cycle}/plazo', [WeeklyCycleController::class, 'deadline'])->whereNumber('cycle')->name('weeklies.deadline.update');
    Route::post('weeklies/{cycle}/cerrar', [WeeklyCycleController::class, 'close'])->whereNumber('cycle')->name('weeklies.close');

    Route::post('weeklies/{cycle}/informe', [WeeklyReportController::class, 'store'])->whereNumber('cycle')->middleware('throttle:10,1,weeklies.report.store')->name('weeklies.report.store');
    Route::put('weeklies/{cycle}/informe', [WeeklyReportController::class, 'update'])->whereNumber('cycle')->name('weeklies.report.update');
    Route::get('weeklies/{cycle}/informe/estado', [WeeklyReportController::class, 'status'])->whereNumber('cycle')->middleware('throttle:120,1,weeklies.report.status')->name('weeklies.report.status');
    Route::get('weeklies/{cycle}/informe/pdf', [WeeklyReportController::class, 'pdf'])->whereNumber('cycle')->middleware('throttle:30,1,weeklies.report.pdf')->name('weeklies.report.pdf');

    Route::post('weeklies/{cycle}/audio', [WeeklyAudioController::class, 'store'])->whereNumber('cycle')->middleware('throttle:10,1,weeklies.audio.store')->name('weeklies.audio.store');
    Route::get('weeklies/{cycle}/audio', [WeeklyAudioController::class, 'download'])->whereNumber('cycle')->middleware('throttle:120,1,weeklies.audio.download')->name('weeklies.audio.download');
    Route::get('weeklies/{cycle}/audio/{section}', [WeeklyAudioController::class, 'show'])->whereNumber(['cycle', 'section'])->middleware('signed:relative')->name('weeklies.audio.show');

    // Exenciones (10.2).
    Route::post('weeklies/{cycle}/exenciones', [WeeklyExemptionController::class, 'store'])->whereNumber('cycle')->name('weeklies.exemptions.store');
    Route::post('weeklies/{cycle}/exenciones/renuncia', [WeeklyExemptionController::class, 'waive'])->whereNumber('cycle')->name('weeklies.exemptions.waive');
    Route::delete('weeklies/{cycle}/exenciones/{exemption}', [WeeklyExemptionController::class, 'destroy'])->whereNumber(['cycle', 'exemption'])->name('weeklies.exemptions.destroy');

    // Avisos (10.5).
    Route::get('weeklies/avisos', [WeeklyReminderController::class, 'edit'])->name('weeklies.reminders.edit');
    Route::put('weeklies/avisos', [WeeklyReminderController::class, 'update'])->name('weeklies.reminders.update');
    Route::post('weeklies/avisos/enviar', [WeeklyReminderController::class, 'send'])->middleware('throttle:10,1,weeklies.reminders.send')->name('weeklies.reminders.send');
    Route::post('weeklies/{cycle}/recordar', [WeeklyReminderController::class, 'remind'])->whereNumber('cycle')->middleware('throttle:30,1,weeklies.reminders.remind')->name('weeklies.reminders.remind');

    // Estado de proyectos con datos reales (10.4, D-148).
    Route::get('weeklies/estado-proyectos', ProjectStatusController::class)->middleware('module:project_status')->name('weeklies.project-status');

    // Mi espacio: mi weekly (10.2), dictado (10.2) y tareas (10.6).
    Route::get('mi-espacio', [MySpaceController::class, 'index'])->name('my-space.index');
    Route::put('mi-espacio/weeklies/{cycle}', [MyWeeklyController::class, 'draft'])->whereNumber('cycle')->middleware('throttle:120,1,my-weekly.draft')->name('my-weekly.draft');
    Route::post('mi-espacio/weeklies/{cycle}/enviar', [MyWeeklyController::class, 'submit'])->whereNumber('cycle')->middleware('throttle:30,1,my-weekly.submit')->name('my-weekly.submit');
    Route::post('mi-espacio/dictados', [DictationController::class, 'store'])->middleware('throttle:30,1,dictations.store')->name('dictations.store');
    Route::get('mi-espacio/dictados/{dictation}', [DictationController::class, 'show'])->whereNumber('dictation')->middleware('throttle:240,1,dictations.show')->name('dictations.show');
    // Unirme a clientes y dejarlos (10.2, F-034; D-221: suscripción de la Weekly, nunca membresía de proyecto).
    Route::post('mi-espacio/clientes', [WeeklyClientSubscriptionController::class, 'join'])->middleware('throttle:30,1,weeklies.clients.join')->name('weeklies.clients.join');
    Route::delete('mi-espacio/clientes/{client}', [WeeklyClientSubscriptionController::class, 'leave'])->whereNumber('client')->middleware('throttle:30,1,weeklies.clients.leave')->name('weeklies.clients.leave');
    Route::post('mi-espacio/tareas/sugeridas', [MySpaceTaskController::class, 'suggest'])->middleware('throttle:10,1,my-space.tasks.suggest')->name('my-space.tasks.suggest');
    Route::post('mi-espacio/tareas/sugeridas/crear', [MySpaceTaskController::class, 'accept'])->middleware('throttle:30,1,my-space.tasks.suggestions.accept')->name('my-space.tasks.suggestions.accept');
    Route::delete('mi-espacio/tareas/sugeridas', [MySpaceTaskController::class, 'dismiss'])->middleware('throttle:30,1,my-space.tasks.suggestions.dismiss')->name('my-space.tasks.suggestions.dismiss');
    Route::put('mi-espacio/tareas/{task}/notas', [MySpaceTaskController::class, 'notes'])->whereNumber('task')->middleware('throttle:120,1,my-space.tasks.notes')->name('my-space.tasks.notes');
    Route::post('mi-espacio/tareas/{task}/archivar', [MySpaceTaskController::class, 'archive'])->whereNumber('task')->name('my-space.tasks.archive');
    Route::delete('mi-espacio/tareas/{task}/archivar', [MySpaceTaskController::class, 'unarchive'])->whereNumber('task')->name('my-space.tasks.unarchive');

    // Equipo y ficha de persona (10.4); resúmenes IA solo para el admin y sus responsables (D-147).
    Route::get('equipo', [TeamController::class, 'index'])->name('team.index');
    Route::get('equipo/{user}', [TeamController::class, 'show'])->whereNumber('user')->name('team.show');
    Route::post('equipo/{user}/resumen-ia', [TeamController::class, 'aiSummary'])->whereNumber('user')->middleware('throttle:10,1,team.ai-summary')->name('team.ai-summary');

    // Ficha de cliente de la Weekly (10.4): resumen IA y actividad del equipo con IA.
    Route::post('clientes/{client}/resumen-ia', [ClientInsightsController::class, 'summary'])->whereNumber('client')->middleware('throttle:10,1,clients.ai-summary')->name('clients.ai-summary');
    Route::post('clientes/{client}/actividad-ia', [ClientInsightsController::class, 'teamActivity'])->whereNumber('client')->middleware('throttle:10,1,clients.team-activity')->name('clients.team-activity');
});

// Versión de la interfaz (10.2, F-013): la pestaña la compara con la suya para avisar de una nueva.
Route::get('version', AppVersionController::class)
    ->middleware('throttle:60,1,app.version')
    ->name('app.version');

// «Uso de IA» (10.3, F-173 y F-180): solo admins.
Route::get('admin/uso-ia', AiUsageController::class)->name('admin.ai-usage.index');

// Asistente IA (10.6, D-205 y D-206): la respuesta llega por la cola `ai`.
Route::middleware('module:assistant')->group(function () {
    Route::get('ia', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('ia/preguntas', [AssistantController::class, 'ask'])->middleware('throttle:20,1,assistant.ask')->name('assistant.ask');
    Route::get('ia/preguntas/{question}', [AssistantController::class, 'show'])->whereUuid('question')->middleware('throttle:240,1,assistant.questions.show')->name('assistant.questions.show');
});

// Centro de ayuda (10.7).
Route::middleware('module:help')->group(function () {
    Route::get('ayuda', [HelpController::class, 'index'])->name('help.index');
    Route::get('ayuda/manual', [HelpController::class, 'manual'])->middleware('signed:relative')->name('help.manual');
    Route::put('ayuda/ajustes', [HelpController::class, 'updateSettings'])->name('help.settings.update');

    Route::post('ayuda/novedades', [HelpContentController::class, 'storeRelease'])->name('help.releases.store');
    Route::put('ayuda/novedades/{release}', [HelpContentController::class, 'updateRelease'])->whereNumber('release')->name('help.releases.update');
    Route::delete('ayuda/novedades/{release}', [HelpContentController::class, 'destroyRelease'])->whereNumber('release')->name('help.releases.destroy');
    Route::post('ayuda/actualizaciones', [HelpContentController::class, 'storeUpdate'])->name('help.updates.store');
    Route::put('ayuda/actualizaciones/{manualUpdate}', [HelpContentController::class, 'updateUpdate'])->whereNumber('manualUpdate')->name('help.updates.update');
    Route::delete('ayuda/actualizaciones/{manualUpdate}', [HelpContentController::class, 'destroyUpdate'])->whereNumber('manualUpdate')->name('help.updates.destroy');
    Route::post('ayuda/me-gusta', [HelpContentController::class, 'like'])->middleware('throttle:60,1,help.likes.toggle')->name('help.likes.toggle');

    // El vídeo sube por trozos de 8 MB (D-207): el PHP del servidor no admite 200 MB de una vez.
    Route::post('ayuda/tutoriales/subidas', [HelpContentController::class, 'startUpload'])->middleware('throttle:30,1,help.tutorials.uploads.store')->name('help.tutorials.uploads.store');
    Route::post('ayuda/tutoriales/subidas/{upload}', [HelpContentController::class, 'uploadChunk'])->whereUuid('upload')->middleware('throttle:300,1,help.tutorials.uploads.chunk')->name('help.tutorials.uploads.chunk');
    Route::post('ayuda/tutoriales', [HelpContentController::class, 'storeTutorial'])->name('help.tutorials.store');
    Route::put('ayuda/tutoriales/orden', [HelpContentController::class, 'reorderTutorials'])->name('help.tutorials.reorder');
    Route::put('ayuda/tutoriales/{tutorial}', [HelpContentController::class, 'updateTutorial'])->whereNumber('tutorial')->name('help.tutorials.update');
    Route::delete('ayuda/tutoriales/{tutorial}', [HelpContentController::class, 'destroyTutorial'])->whereNumber('tutorial')->name('help.tutorials.destroy');
    Route::get('ayuda/tutoriales/{tutorial}/video', [HelpContentController::class, 'video'])->whereNumber('tutorial')->middleware('signed:relative')->name('help.tutorials.video');

    Route::post('ayuda/secciones', [HelpContentController::class, 'storeSection'])->name('help.faq-sections.store');
    Route::put('ayuda/secciones/orden', [HelpContentController::class, 'reorderSections'])->name('help.faq-sections.reorder');
    Route::put('ayuda/secciones/{faqSection}', [HelpContentController::class, 'updateSection'])->whereNumber('faqSection')->name('help.faq-sections.update');
    Route::delete('ayuda/secciones/{faqSection}', [HelpContentController::class, 'destroySection'])->whereNumber('faqSection')->name('help.faq-sections.destroy');
    Route::post('ayuda/preguntas', [HelpContentController::class, 'storeFaq'])->name('help.faqs.store');
    Route::put('ayuda/preguntas/orden', [HelpContentController::class, 'reorderFaqs'])->name('help.faqs.reorder');
    Route::put('ayuda/preguntas/{faq}', [HelpContentController::class, 'updateFaq'])->whereNumber('faq')->name('help.faqs.update');
    Route::delete('ayuda/preguntas/{faq}', [HelpContentController::class, 'destroyFaq'])->whereNumber('faq')->name('help.faqs.destroy');
});

// Sugerencias (10.7): el listado es /ayuda?pestana=sugerencias.
Route::middleware(['module:help', 'module:suggestions'])->group(function () {
    Route::post('ayuda/sugerencias', [SuggestionController::class, 'store'])->middleware('throttle:30,1,suggestions.store')->name('suggestions.store');
    Route::get('ayuda/sugerencias/similares', [SuggestionController::class, 'similar'])->middleware('throttle:120,1,suggestions.similar')->name('suggestions.similar');
    Route::get('ayuda/sugerencias/{post}', [SuggestionController::class, 'show'])->whereNumber('post')->name('suggestions.show');
    Route::put('ayuda/sugerencias/{post}', [SuggestionController::class, 'update'])->whereNumber('post')->middleware('throttle:30,1,suggestions.update')->name('suggestions.update');
    Route::delete('ayuda/sugerencias/{post}', [SuggestionController::class, 'destroy'])->whereNumber('post')->name('suggestions.destroy');
    Route::post('ayuda/sugerencias/{post}/voto', [SuggestionController::class, 'vote'])->whereNumber('post')->middleware('throttle:60,1,suggestions.vote')->name('suggestions.vote');
    Route::put('ayuda/sugerencias/{post}/estado', [SuggestionController::class, 'status'])->whereNumber('post')->name('suggestions.status.update');
    Route::put('ayuda/sugerencias/{post}/orden', [SuggestionController::class, 'position'])->whereNumber('post')->name('suggestions.position.update');

    Route::post('ayuda/sugerencias/{post}/comentarios', [SuggestionCommentController::class, 'store'])->whereNumber('post')->middleware('throttle:60,1,suggestions.comments.store')->name('suggestions.comments.store');
    Route::put('ayuda/sugerencias/comentarios/{suggestionComment}', [SuggestionCommentController::class, 'update'])->whereNumber('suggestionComment')->middleware('throttle:60,1,suggestions.comments.update')->name('suggestions.comments.update');
    Route::delete('ayuda/sugerencias/comentarios/{suggestionComment}', [SuggestionCommentController::class, 'destroy'])->whereNumber('suggestionComment')->name('suggestions.comments.destroy');
    Route::post('ayuda/sugerencias/comentarios/{suggestionComment}/reaccion', [SuggestionCommentController::class, 'react'])->whereNumber('suggestionComment')->middleware('throttle:60,1,suggestions.comments.react')->name('suggestions.comments.react');

    Route::post('ayuda/sugerencias/tableros', [SuggestionBoardController::class, 'storeBoard'])->name('suggestions.boards.store');
    Route::put('ayuda/sugerencias/tableros/orden', [SuggestionBoardController::class, 'reorderBoards'])->name('suggestions.boards.reorder');
    Route::put('ayuda/sugerencias/tableros/{board}/categorias/orden', [SuggestionBoardController::class, 'reorderCategories'])->whereNumber('board')->name('suggestions.categories.reorder');
    Route::put('ayuda/sugerencias/tableros/{board}', [SuggestionBoardController::class, 'updateBoard'])->whereNumber('board')->name('suggestions.boards.update');
    Route::delete('ayuda/sugerencias/tableros/{board}', [SuggestionBoardController::class, 'destroyBoard'])->whereNumber('board')->name('suggestions.boards.destroy');
    Route::post('ayuda/sugerencias/tableros/{board}/categorias', [SuggestionBoardController::class, 'storeCategory'])->whereNumber('board')->name('suggestions.categories.store');
    Route::put('ayuda/sugerencias/categorias/{category}', [SuggestionBoardController::class, 'updateCategory'])->whereNumber('category')->name('suggestions.categories.update');
    Route::delete('ayuda/sugerencias/categorias/{category}', [SuggestionBoardController::class, 'destroyCategory'])->whereNumber('category')->name('suggestions.categories.destroy');
});
