/*
 * Training learner player - spec §5.9 (preview as learner; the Phase 3 kiosk reuses it), A9/A17 look.
 * Vanilla JS, no dependencies beyond an optional TrainingVideoEmbed (js/training_video_embed.js).
 *
 *   TrainingPlayer.mount(root, view, adapter) -> {destroy(), open(uid), home(), reset(), progress()}
 *       view     LearnerView v1 (§3.7) - never contains an answer key
 *       adapter  { mode:'preview'|'kiosk', canGrade, brand?, learnerName?,
 *                  startQuiz(lessonUid, lang) -> Promise<preview_quiz_start data>,
 *                  submitQuiz(token, answers) -> Promise<preview_quiz_submit data>,
 *                  onLessonOpen(uid), onLessonComplete(uid, evidence) -> Promise,
 *                  onVideoReady(lesson, lang, {provider, extId, extHash, durationS}) -> Promise<{verified, check}>|void,
 *                  onVideoError(lesson, lang, {provider, extId, extHash, code}),
 *                  initialProgress?, onProgress?(progress), onLanguage?(lang), initialLesson? }
 *
 *       Kiosk hooks (P3 spec §7.6; every one optional and additive - preview behaviour is unchanged):
 *                  chrome:false                 no .trp-top (the kiosk shell owns brand / EN|ES / Done)
 *                  ensureRun() -> Promise<RunState>   awaited once before the first lesson opens; its done map is merged
 *                  onLessonOpen(uid) -> Promise<Gate> kiosk: the server gate drives Mark complete (skipped when gate.quiz)
 *                  onTick(uid, sample) -> Promise<Gate>   sample {position_s, pages_seen:[new], playing, visible, active}
 *                  onLessonComplete(uid, evidence) -> Promise<{done, run_status, ...}>  kiosk: done only after it resolves
 *                  signAck(uid, {signature_png, pin}) -> Promise<{run_status, receipt}>   kiosk acknowledgment
 *                  signaturePad(container, {onChange, name, date}) -> {toPng, clear, isInked, destroy}
 *                  externalVideoUrl(uid)        YouTube/Vimeo open on their own page (a card with Watch video)
 *                  onAnswer(token, qUid, optionUids) -> Promise   debounced 400 ms, flushed before submit
 *                  quizInfo(uid) -> {used, max, left, locked, passed, check?, must_pass?}|null  (quiz lessons and quick checks)
 *                  onCheckState(uid, info)       the server's quick-check state after a lesson's content is credited
 *                  initialCheck                  a lesson uid whose quick check opens first (back from the video page)
 *                  onQuizResult(uid, res) -> {signLabel}|null ; onSign() ; onCourseComplete() (replaces the completion screen)
 *                  courseChips() -> [{icon, text, tone}] ; homeNotice() -> {tone, icon, title, text}|null ;
 *                  homeCta() -> {label, icon, onClick, disabled}|null ; homeSubline() -> string|null ;
 *                  runFrozen() -> truthy when the run takes no lesson work (locked, blocked, awaiting_*)
 *                  frozenRowLabel() -> string|null   what a frozen run's lesson rows say (kiosk: "Locked · see your trainer")
 *                  checkWhileFrozen() -> truthy while a done lesson's optional quick check may still be taken (awaiting the sign-off)
 *                  learnerFirst, validityMonths
 *                  mediaPrefs: {getVolume() -> {level, muted}|null, setVolume(level, muted), getCc() -> bool|null, setCc(on)}
 *                               where the video options are remembered (kiosk: volume per device, captions for
 *                               the signed-in person; preview: both per device). Without it they last for the page.
 *
 *       Video options (owner ask 2026-09-27; widgets in js/training_media_controls.js): Mute / volume slider
 *       (Mute only on iPhone / iPad, where iOS ignores a page's volume; Up / Down arrows +-10 %), a CC button
 *       when the uploaded video has caption files (large white-on-black text over the video; EN | ES when both
 *       languages' captions fit this video; the course language first) or through the YouTube / Vimeo player
 *       (preview), and "Resuming at 3:42 · Start over" when a started video is opened again - kiosk: at the
 *       run's furthest point (lesson_open's max_position_s), preview: at the last position kept in progress.pos.
 *       A PDF opens at its first page not yet seen (kiosk: gate.pages_seen_list). Credit rules are unchanged:
 *       seeking past the furthest point watched stays blocked.
 *
 *       Quick checks (a quiz with role 'check' on an article, document, video or image lesson): once the
 *       lesson's content is done the player offers the check - one question per screen, the same
 *       runner and result screen as a quiz, "Try again" while tries remain. Not must-pass: the lesson is
 *       done with its content and the check can be skipped. Must-pass: the lesson is done only after a
 *       pass (kiosk: the server's done map says so; progress.credited holds "content done").
 *
 *   TrainingPlayer.renderQuestion(container, q, opts) -> {destroy()}
 *       q    {uid?, type, text, image_url, options:[{uid, text}]} - the builder strips every key field first
 *       opts {mode:'author', index, total, title, strings?, lang?, brand?}
 *
 * Security: the only HTML sinks are the server-purified description_html, body_html and
 * statement_html (LearnerView re-purifies them at projection). Every other string - titles,
 * questions, options, feedback - goes through textContent. Nothing is written to storage here;
 * the adapter decides what (if anything) to remember, and never question or option data.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------------------------------
    // Strings: LearnerView.strings first, then these player-level extras (EN / ES).
    // ------------------------------------------------------------------------------------------
    var EXTRA = {
        en: {
            lessons_n: '{n} lessons', lesson_1: '1 lesson', course_content: 'Course content', sections_meta: '{s} sections · {n} lessons',
            section_n: 'Section {n}', done: 'Done', in_progress: 'In progress', n_of_m_done: '{n} of {m} done',
            lessons_done: '{n} of {m} lessons done', continue_to: 'Continue: {title}', start_course: 'Start course', review_course: 'Review course',
            up_next: 'Up next', lesson_n_of: 'Lesson {n} of {total}', next_lesson: 'Next lesson', back_to_course: 'Back to course',
            t_article: 'Article', t_document: 'Document (PDF)', t_video: 'Video', t_image: 'Image', t_quiz: 'Quiz', t_acknowledgment: 'Acknowledgment',
            t_exam: 'Final exam', t_check: 'Quick check',
            min_read: '{n} min read', pages_n: '{n} pages', page_1: '1 page', questions_n: '{n} questions', question_1: '1 question',
            read_and_sign: 'read and sign', pass_pct: 'Pass {pct}%', attempts_n: '{n} attempts', attempts_1: '1 attempt', unlimited: 'Unlimited tries',
            time_limit_min: '{n} min limit', pages_viewed: '{n} of {total} pages viewed', go_through_pages: 'Go through all {n} pages to finish',
            all_pages_viewed: 'All pages viewed', swipe_hint: 'Swipe to turn pages · pinch to zoom',
            watch_progress: 'Watch progress', keep_watching: 'Watched {pct}% — keep watching to finish', watched_enough: 'Watched {pct}% — you can finish this lesson',
            about_left: 'About {t} left', skip_not_counted: "Skipping ahead isn't counted.", only_watched: 'Only what you watch adds up.',
            furthest: 'Furthest watched', fullscreen: 'Full screen', exit_fullscreen: 'Exit full screen',
            req_watch: 'watching {pct}% of the video', req_pages: 'viewing all {n} pages', req_quiz: 'passing the quiz', req_sign: 'ticking the box and signing',
            available_after: 'Available after {req}', ready_to_finish: 'Ready to finish', resources_1: '1 file',
            preview_gate: 'Learners finish this after {req}. In preview you can continue anyway.', verifying: 'Checking the video…', verified_at: 'Verified · {t}',
            video_note_upload: 'Video', answered_n: '{n} of {total} answered', pick_one: 'Pick one answer', pick_tf: 'True or false',
            question_kicker: 'Question {n}', flag: 'Flag for review', flagged: 'Flagged', next_question: 'Next question', review_answers: 'Review answers',
            n_unanswered: '{n} not answered. Unanswered questions count as wrong.', all_answered: 'Every question is answered.',
            you_passed: 'You passed!', not_passed: 'Not this time', pass_mark: 'Pass mark {pct}%', points_of: '{n} of {m} points',
            correct_label: 'Correct', time_label: 'Time', passmark_label: 'Pass mark', of: '{n} of {m}', took: 'took {t}', finished_at: 'Finished {time}',
            what_to_review: 'What to review', n_missed: '{n} missed', quick_look: 'A quick look now helps it stick for next time.',
            nothing_missed: 'Nothing missed. Nice work.', score_only_note: 'This quiz shows the score only.',
            no_achievements: 'Achievements are awarded once the Learning Center launches.', time_up: "Time's up. Your answers were sent.",
            time_up_ungraded: "Time's up.", leave_quiz: 'Leave quiz', passed_chip: 'Passed', failed_chip: 'Not yet',
            pass_msg: 'Nice work. You can continue to the next lesson.', fail_msg: 'Review the questions below, then try again.',
            fail_critical_msg: 'A must-know question was missed. Review it, then try again.', quiz_not_graded: 'Preview only: answers are not graded at this level.',
            submitting: 'Sending your answers…', starting: 'Getting your questions…', ack_title: 'Read and sign',
            signature_needed: 'Sign in the box to continue', pin_hint: 'Dots only. The PIN is never sent in preview.', pin_label: 'PIN',
            complete_title: 'Course complete', complete_sub: 'You finished {course}.', completed_on: 'Completed {date}', learner: 'Preview learner',
            attest_by: 'Signed by {name}', open_image: 'Open full size', about_course: 'About this course', learner_view: 'Preview',
            resources_n: '{n} files', mark_done: 'Mark done', optional_chip: 'Optional', open_chip: 'Open without starting',
            locked_chip: 'Locked', start_chip: 'Start', review_chip: 'Review', correct_answers: 'Correct answer', answer_label: 'Answer {l}',
            course_name_label: 'Course', minutes_total: '{n} min', video_error_detail: 'Details: {code}', play_first: 'Press play inside the video first.',
            review_hint: 'Tap a question to change your answer.', graded_hidden: 'Grading is available to course authors', in_order: 'Lessons in order', attest_default: 'I completed this training and I understand it.',
            question_of: 'Question {n} of {total}', next: 'Next', select_all: 'Select all that apply',
            section_1: '1 section', sections_n: '{n} sections', under_minute: 'under a minute', tries_left: 'Tries left', unlimited_short: 'Unlimited',
            attempt_label: 'Attempt', good_for: 'Good for', months_n: '{n} months', month_1: '1 month', attempt_of: '{n} of {m}',
            ack_todo_all: 'Tick the box, sign and enter your PIN', ack_todo_sign: 'Tick the box and sign', ack_todo_pin: 'Tick the box and enter your PIN', ack_todo_tick: 'Tick the box',
            // kiosk (P3 §7.6)
            k_checking: 'Checking your progress…', k_keep_going: 'Keep going: about {t} more', k_saving_note: 'Answers save as you go',
            k_save_failed: "Couldn't save that answer yet. It is sent again when you finish.", k_score_saved: 'Your score is saved. Signing adds it to your training record.',
            k_score_recorded: 'Your score is saved.', k_attempt_of: 'Attempt {n} of {max}', attempt_n: 'Attempt {n}', attempts_left_n: '{n} tries left', attempts_left_1: '1 try left',
            locked_trainer: 'Locked — see your trainer', ach_unlocked: 'Achievement unlocked', ach_unlocked_n: 'Achievements unlocked', ach_added: 'Added to your profile', sign_to_finish: 'Sign to finish', watch_video: 'Watch video',
            ext_video_note: 'The video opens on its own screen. Come back here when you finish.', k_pin_hint: 'Same PIN you use to sign in.',
            k_pin_doc_hint: 'Your PIN confirms you read this document.', exam_after: 'Finish the other lessons first', k_saving: 'Saving…',
            k_pass_sign_msg: 'Nice work, {first}. One last step: sign to put {course} on your training record.', k_pass_msg: 'Nice work, {first}.',
            k_locked_msg: 'You used all your tries. Your trainer can give you another try.', k_retry_msg: 'Review the questions below, then try again.',
            k_read_first: 'Take a moment to read it, then sign.', k_passed_already: 'You passed this quiz.', passed_chip_short: 'Passed', minutes_short: '{n} min',
            k_finish_first: 'Finish this lesson first.', k_arrows_hint: 'Use the arrows to turn pages',
            k_ack_tsp: 'Tick the box, sign, then enter your PIN', k_ack_ts: 'Tick the box and sign', k_ack_tp: 'Tick the box, then enter your PIN', k_ack_t: 'Tick the box',
            k_ack_sp: 'Sign, then enter your PIN', k_ack_s: 'Sign in the box', k_ack_p: 'Enter your PIN', k_ack_step: 'Step 1 of 2 · then sign the course',
            k_exam_start: 'Start the exam', k_exam_leave: 'Leave exam', k_exam_after: 'Available after passing the exam',
            // quick checks on content lessons
            qc_start: 'Start quick check', qc_take: 'Take the quick check', qc_skip: 'Skip for now', qc_back_lesson: 'Back to the lesson',
            qc_intro: 'A few questions on what you just learned.', qc_optional: "Doesn't count toward finishing. Missed questions show why.",
            qc_must: 'Pass this quick check to finish the lesson.', qc_passed: 'You passed this quick check.', qc_no_tries: 'No tries left for this quick check.',
            qc_foot_must: 'Pass the quick check to finish this lesson', qc_foot_optional: 'Optional · the lesson is already done',
            qc_pending: 'Quick check to pass', qc_leave: 'Leave quick check', qc_fail_msg: 'Look over what you missed. You can try again or continue.',
            qc_fail_must_msg: 'Look over what you missed, then try again.', qc_title: 'Quick check: {title}', qc_chip: 'Quick check',
            qc_resume: 'Continue quick check', qc_open: 'You started this quick check. Your answers so far are saved.',
            qc_continue: 'Continue to quick check', qc_take_to: 'Take the quick check: {title}',
            qc_then_n: 'Then a quick check · {n} questions', qc_then_1: 'Then a quick check · 1 question',
            qc_then_must_n: 'Then pass a {n}-question quick check', qc_then_must_1: 'Then pass a 1-question quick check',
            qc_must_tries: "If you don't pass in {n} tries, your trainer has to unlock the course.",
            qc_must_last: "This is your last try. If you don't pass, your trainer has to unlock the course.",
            qc_fail_last: "One try left. If you don't pass it, your trainer has to unlock the course.",
            qc_passed_sign: 'Quick check passed. Sign to finish.', qc_not_record: "Doesn't go on your training record.",
            qc_again_video: 'Watch again', qc_again_read: 'Read again', qc_again_image: 'Look again',
            qc_optional_chip: 'Quick check (optional)', qc_correct_n: '{n} of {m} correct', qc_watched: 'Watched {pct}% — the quick check is next',
            // video options
            vol_mute: 'Mute', vol_unmute: 'Unmute', volume: 'Volume', cc: 'Captions', cc_short: 'CC', cc_none: 'No captions for this video',
            cc_lang: 'Caption language', lang_en: 'English', lang_es: 'Spanish', resume_at: 'Resuming at {t}', start_over: 'Start over',
            resume_page: 'Picked up at page {n}', back_to_first: 'Back to page 1'
        },
        es: {
            lessons_n: '{n} lecciones', lesson_1: '1 lección', course_content: 'Contenido del curso', sections_meta: '{s} secciones · {n} lecciones',
            section_n: 'Sección {n}', done: 'Hecho', in_progress: 'En curso', n_of_m_done: '{n} de {m} hechas',
            lessons_done: '{n} de {m} lecciones hechas', continue_to: 'Continuar: {title}', start_course: 'Comenzar el curso', review_course: 'Repasar el curso',
            up_next: 'Siguiente', lesson_n_of: 'Lección {n} de {total}', next_lesson: 'Siguiente lección', back_to_course: 'Volver al curso',
            t_article: 'Artículo', t_document: 'Documento (PDF)', t_video: 'Video', t_image: 'Imagen', t_quiz: 'Prueba', t_acknowledgment: 'Constancia',
            t_exam: 'Examen final', t_check: 'Prueba rápida',
            min_read: '{n} min de lectura', pages_n: '{n} páginas', page_1: '1 página', questions_n: '{n} preguntas', question_1: '1 pregunta',
            read_and_sign: 'leer y firmar', pass_pct: 'Aprobar con {pct}%', attempts_n: '{n} intentos', attempts_1: '1 intento', unlimited: 'Intentos ilimitados',
            time_limit_min: 'Límite de {n} min', pages_viewed: '{n} de {total} páginas vistas', go_through_pages: 'Revise las {n} páginas para terminar',
            all_pages_viewed: 'Todas las páginas vistas', swipe_hint: 'Deslice para cambiar de página · pellizque para acercar',
            watch_progress: 'Progreso del video', keep_watching: 'Visto {pct}% — siga viendo para terminar', watched_enough: 'Visto {pct}% — ya puede terminar esta lección',
            about_left: 'Faltan unos {t}', skip_not_counted: 'Adelantar no cuenta.', only_watched: 'Solo cuenta lo que ve.',
            furthest: 'Lo más lejos visto', fullscreen: 'Pantalla completa', exit_fullscreen: 'Salir de pantalla completa',
            req_watch: 'ver el {pct}% del video', req_pages: 'ver las {n} páginas', req_quiz: 'aprobar la prueba', req_sign: 'marcar la casilla y firmar',
            available_after: 'Disponible después de {req}', ready_to_finish: 'Listo para terminar', resources_1: '1 archivo',
            preview_gate: 'Los participantes terminan esto después de {req}. En la vista previa puede continuar.', verifying: 'Verificando el video…', verified_at: 'Verificado · {t}',
            video_note_upload: 'Video', answered_n: '{n} de {total} respondidas', pick_one: 'Elija una respuesta', pick_tf: 'Verdadero o falso',
            question_kicker: 'Pregunta {n}', flag: 'Marcar para revisar', flagged: 'Marcada', next_question: 'Siguiente pregunta', review_answers: 'Revisar respuestas',
            n_unanswered: '{n} sin responder. Las preguntas sin respuesta cuentan como incorrectas.', all_answered: 'Todas las preguntas tienen respuesta.',
            you_passed: '¡Aprobó!', not_passed: 'Esta vez no', pass_mark: 'Nota para aprobar {pct}%', points_of: '{n} de {m} puntos',
            correct_label: 'Correctas', time_label: 'Tiempo', passmark_label: 'Para aprobar', of: '{n} de {m}', took: 'tardó {t}', finished_at: 'Terminó {time}',
            what_to_review: 'Qué repasar', n_missed: '{n} falladas', quick_look: 'Un repaso rápido ahora ayuda a recordarlo.',
            nothing_missed: 'No falló ninguna. Buen trabajo.', score_only_note: 'Esta prueba solo muestra la puntuación.',
            no_achievements: 'Los logros se otorgan cuando se lance el Centro de aprendizaje.', time_up: 'Se acabó el tiempo. Se enviaron sus respuestas.',
            time_up_ungraded: 'Se acabó el tiempo.', leave_quiz: 'Salir de la prueba', passed_chip: 'Aprobado', failed_chip: 'Todavía no',
            pass_msg: 'Buen trabajo. Puede continuar con la siguiente lección.', fail_msg: 'Repase las preguntas de abajo y vuelva a intentarlo.',
            fail_critical_msg: 'Falló una pregunta esencial. Repásela y vuelva a intentarlo.', quiz_not_graded: 'Solo vista previa: las respuestas no se califican en este nivel.',
            submitting: 'Enviando sus respuestas…', starting: 'Preparando sus preguntas…', ack_title: 'Leer y firmar',
            signature_needed: 'Firme en el recuadro para continuar', pin_hint: 'Solo puntos. El PIN nunca se envía en la vista previa.', pin_label: 'PIN',
            complete_title: 'Curso completado', complete_sub: 'Terminó {course}.', completed_on: 'Completado el {date}', learner: 'Participante de prueba',
            attest_by: 'Firmado por {name}', open_image: 'Ver en tamaño completo', about_course: 'Acerca de este curso', learner_view: 'Vista previa',
            resources_n: '{n} archivos', mark_done: 'Marcar como hecho', optional_chip: 'Opcional', open_chip: 'Abrir sin comenzar',
            locked_chip: 'Bloqueada', start_chip: 'Comenzar', review_chip: 'Repasar', correct_answers: 'Respuesta correcta', answer_label: 'Respuesta {l}',
            course_name_label: 'Curso', minutes_total: '{n} min', video_error_detail: 'Detalle: {code}', play_first: 'Primero presione reproducir dentro del video.',
            review_hint: 'Toque una pregunta para cambiar su respuesta.', graded_hidden: 'La calificación está disponible para los autores del curso', in_order: 'Lecciones en orden', attest_default: 'Completé esta capacitación y la entiendo.',
            question_of: 'Pregunta {n} de {total}', next: 'Siguiente', select_all: 'Seleccione todas las que correspondan',
            section_1: '1 sección', sections_n: '{n} secciones', under_minute: 'menos de un minuto', tries_left: 'Intentos restantes', unlimited_short: 'Ilimitados',
            attempt_label: 'Intento', good_for: 'Válido por', months_n: '{n} meses', month_1: '1 mes', attempt_of: '{n} de {m}',
            ack_todo_all: 'Marque la casilla, firme e ingrese su PIN', ack_todo_sign: 'Marque la casilla y firme', ack_todo_pin: 'Marque la casilla e ingrese su PIN', ack_todo_tick: 'Marque la casilla',
            // kiosk (P3 §7.6)
            k_checking: 'Revisando su avance…', k_keep_going: 'Siga: faltan unos {t}', k_saving_note: 'Las respuestas se guardan solas',
            k_save_failed: 'Todavía no se guardó esa respuesta. Se envía otra vez al terminar.', k_score_saved: 'Su puntuación está guardada. Al firmar queda en su registro de capacitación.',
            k_score_recorded: 'Su puntuación está guardada.', k_attempt_of: 'Intento {n} de {max}', attempt_n: 'Intento {n}', attempts_left_n: 'Le quedan {n} intentos', attempts_left_1: 'Le queda 1 intento',
            locked_trainer: 'Bloqueado — hable con su instructor', ach_unlocked: 'Logro desbloqueado', ach_unlocked_n: 'Logros desbloqueados', ach_added: 'Agregado a su perfil', sign_to_finish: 'Firmar para terminar', watch_video: 'Ver el video',
            ext_video_note: 'El video se abre en su propia pantalla. Vuelva aquí cuando termine.', k_pin_hint: 'El mismo PIN que usa para entrar.',
            k_pin_doc_hint: 'Su PIN confirma que leyó este documento.', exam_after: 'Primero termine las otras lecciones', k_saving: 'Guardando…',
            k_pass_sign_msg: 'Buen trabajo, {first}. Un último paso: firme para que {course} quede en su registro.', k_pass_msg: 'Buen trabajo, {first}.',
            k_locked_msg: 'Usó todos sus intentos. Su instructor le puede dar otro intento.', k_retry_msg: 'Repase las preguntas de abajo y vuelva a intentarlo.',
            k_read_first: 'Tómese un momento para leerlo y luego firme.', k_passed_already: 'Ya aprobó esta prueba.', passed_chip_short: 'Aprobado', minutes_short: '{n} min',
            k_finish_first: 'Primero termine esta lección.', k_arrows_hint: 'Use las flechas para cambiar de página',
            k_ack_tsp: 'Marque la casilla, firme y escriba su PIN', k_ack_ts: 'Marque la casilla y firme', k_ack_tp: 'Marque la casilla y escriba su PIN', k_ack_t: 'Marque la casilla',
            k_ack_sp: 'Firme y escriba su PIN', k_ack_s: 'Firme en el recuadro', k_ack_p: 'Escriba su PIN', k_ack_step: 'Paso 1 de 2 · después firme el curso',
            k_exam_start: 'Empezar el examen', k_exam_leave: 'Salir del examen', k_exam_after: 'Disponible después de aprobar el examen',
            // pruebas rápidas de las lecciones (una "Prueba" como las demás pruebas del curso)
            qc_start: 'Empezar la prueba rápida', qc_take: 'Hacer la prueba rápida', qc_skip: 'Omitir por ahora', qc_back_lesson: 'Volver a la lección',
            qc_intro: 'Unas preguntas sobre lo que acaba de aprender.', qc_optional: 'No cuenta para terminar. Las preguntas falladas explican por qué.',
            qc_must: 'Apruebe esta prueba rápida para terminar la lección.', qc_passed: 'Ya aprobó esta prueba rápida.', qc_no_tries: 'Ya no le quedan intentos para esta prueba rápida.',
            qc_foot_must: 'Apruebe la prueba rápida para terminar esta lección', qc_foot_optional: 'Opcional · la lección ya está terminada',
            qc_pending: 'Falta aprobar la prueba rápida', qc_leave: 'Salir de la prueba rápida', qc_fail_msg: 'Revise lo que falló. Puede intentarlo otra vez o continuar.',
            qc_fail_must_msg: 'Revise lo que falló y vuelva a intentarlo.', qc_title: 'Prueba rápida: {title}', qc_chip: 'Prueba rápida',
            qc_resume: 'Continuar la prueba rápida', qc_open: 'Ya empezó esta prueba rápida. Sus respuestas están guardadas.',
            qc_continue: 'Continuar a la prueba rápida', qc_take_to: 'Hacer la prueba rápida: {title}',
            qc_then_n: 'Después, una prueba rápida · {n} preguntas', qc_then_1: 'Después, una prueba rápida · 1 pregunta',
            qc_then_must_n: 'Después, apruebe una prueba rápida de {n} preguntas', qc_then_must_1: 'Después, apruebe una prueba rápida de 1 pregunta',
            qc_must_tries: 'Si no aprueba en {n} intentos, su instructor tiene que desbloquear el curso.',
            qc_must_last: 'Es su último intento. Si no aprueba, su instructor tiene que desbloquear el curso.',
            qc_fail_last: 'Le queda un intento. Si no lo aprueba, su instructor tiene que desbloquear el curso.',
            qc_passed_sign: 'Aprobó la prueba rápida. Firme para terminar.', qc_not_record: 'No queda en su registro de capacitación.',
            qc_again_video: 'Ver otra vez', qc_again_read: 'Leer otra vez', qc_again_image: 'Ver la imagen otra vez',
            qc_optional_chip: 'Prueba rápida (opcional)', qc_correct_n: '{n} de {m} correctas', qc_watched: 'Visto {pct}% — sigue la prueba rápida',
            // opciones del video
            vol_mute: 'Silenciar', vol_unmute: 'Activar sonido', volume: 'Volumen', cc: 'Subtítulos', cc_short: 'CC', cc_none: 'Este video no tiene subtítulos',
            cc_lang: 'Idioma de los subtítulos', lang_en: 'Inglés', lang_es: 'Español', resume_at: 'Continúa en {t}', start_over: 'Empezar de nuevo',
            resume_page: 'Sigue en la página {n}', back_to_first: 'Volver a la página 1'
        }
    };
    var LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    var TYPE_ICON = {
        article: 'fa-file-alt', document: 'fa-file-pdf', video: 'fa-play-circle', image: 'fa-image',
        quiz: 'fa-question-circle', acknowledgment: 'fa-file-signature'
    };

    function makeT(strings, lang) {
        var extra = EXTRA[lang] || EXTRA.en;
        return function t(key, vars) {
            var s = (strings && typeof strings[key] === 'string') ? strings[key] : (extra[key] || EXTRA.en[key] || key);
            if (vars) {
                s = s.replace(/\{([a-z_]+)\}/g, function (m, k) { return vars[k] !== undefined && vars[k] !== null ? String(vars[k]) : m; });
            }
            return s;
        };
    }

    // ------------------------------------------------------------------------------------------
    // DOM helpers (no HTML sinks; the three allowlisted fields use setTrustedHtml()).
    // ------------------------------------------------------------------------------------------
    function h(tag, attrs, children) {
        var n = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === undefined || v === null || v === false) { return; }
            if (k === 'class') { n.className = Array.isArray(v) ? v.filter(Boolean).join(' ') : v; }
            else if (k === 'text') { n.textContent = String(v); }
            else if (k === 'on') { Object.keys(v).forEach(function (e) { n.addEventListener(e, v[e]); }); }
            else if (k === 'style') { Object.keys(v).forEach(function (s) { n.style.setProperty(s, v[s]); }); }
            else if (k === 'dataset') { Object.keys(v).forEach(function (d) { n.dataset[d] = String(v[d]); }); }
            else if (/^on/i.test(k) || k === 'html' || k === 'innerHTML') { throw new Error('TrainingPlayer: no inline handlers or HTML sinks'); }
            else if ((k === 'href' || k === 'src') && /^\s*(javascript|vbscript|data):/i.test(String(v))) { throw new Error('TrainingPlayer: unsafe URL'); }
            else if (v === true) { n.setAttribute(k, ''); }
            else { n.setAttribute(k, String(v)); }
        });
        (Array.isArray(children) ? children : [children]).forEach(function (c) {
            if (c === null || c === undefined || c === false) { return; }
            n.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
        });
        return n;
    }
    function icon(name, extra) { return h('i', { class: 'fas ' + name + (extra ? ' ' + extra : ''), 'aria-hidden': 'true' }); }
    /** Server-purified HTML only: description_html, body_html (article), statement_html (ack). */
    function setTrustedHtml(node, html) {
        node.innerHTML = typeof html === 'string' ? html : '';
        node.querySelectorAll('a[href]').forEach(function (a) {
            if (/^https?:/i.test(a.getAttribute('href') || '')) { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer'); }
        });
        return node;
    }
    function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }
    function fmt(seconds) {
        var s = Math.max(0, Math.round(Number(seconds) || 0));
        var hh = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        var pad = function (x) { return (x < 10 ? '0' : '') + x; };
        return hh > 0 ? hh + ':' + pad(m) + ':' + pad(sec) : m + ':' + pad(sec);
    }
    function minutes(seconds) { return Math.max(1, Math.round((Number(seconds) || 0) / 60)); }
    /** A mouse-only screen (Windows PC): wording says "mouse" / "arrows" instead of finger and swipe. */
    function mouseOnly() {
        try {
            return !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches && !window.matchMedia('(any-pointer: coarse)').matches
                && !(navigator.maxTouchPoints > 0) && !('ontouchstart' in window));
        } catch (e) { return false; }
    }
    function reducedMotion() {
        try { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
    }
    function ring(pct, cls, label) {
        var p = Math.max(0, Math.min(100, Number(pct) || 0));
        var svgNs = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('viewBox', '0 0 120 120');
        svg.setAttribute('class', 'trp-ring__svg');
        svg.setAttribute('aria-hidden', 'true');
        var track = document.createElementNS(svgNs, 'circle');
        track.setAttribute('cx', '60'); track.setAttribute('cy', '60'); track.setAttribute('r', '52');
        track.setAttribute('class', 'trp-ring__track');
        var bar = document.createElementNS(svgNs, 'circle');
        bar.setAttribute('cx', '60'); bar.setAttribute('cy', '60'); bar.setAttribute('r', '52');
        bar.setAttribute('class', 'trp-ring__bar');
        var len = 2 * Math.PI * 52;
        bar.setAttribute('stroke-dasharray', String(len));
        bar.setAttribute('stroke-dashoffset', String(len * (1 - p / 100)));
        svg.appendChild(track);
        svg.appendChild(bar);
        return h('div', { class: ['trp-ring', cls], role: 'img', 'aria-label': label || (Math.round(p) + '%') }, [svg]);
    }

    // ------------------------------------------------------------------------------------------
    // Question screen (shared by the runner and the author preview)
    // ------------------------------------------------------------------------------------------
    /**
     * @param q     {uid, type, text, image_url, options:[{uid,text}]}
     * @param state {selected:[uids], flagged:bool}
     * @param o     {t, index, total, compact, onChange(selectedUids)}
     */
    function questionCard(q, state, o) {
        var t = o.t;
        var multi = q.type === 'multi';
        var kicker = t('question_kicker', { n: o.index + 1 }) + ' · ' + (multi ? t('select_all') : (q.type === 'truefalse' ? t('pick_tf') : t('pick_one')));
        var qid = 'trp-q-' + Math.random().toString(36).slice(2, 9);
        var list = h('div', { class: 'trp-opts' + (o.compact && q.options.length <= 4 ? ' trp-opts--grid' : ''), role: multi ? 'group' : 'radiogroup', 'aria-labelledby': qid });
        var buttons = [];
        function sync() {
            buttons.forEach(function (b) {
                var on = state.selected.indexOf(b.dataset.uid) !== -1;
                b.classList.toggle('is-selected', on);
                b.setAttribute('aria-checked', on ? 'true' : 'false');
            });
            if (!multi) {
                // Roving tabindex for the radio group.
                var sel = buttons.filter(function (b) { return b.classList.contains('is-selected'); })[0] || buttons[0];
                buttons.forEach(function (b) { b.tabIndex = b === sel ? 0 : -1; });
            }
        }
        function choose(uid) {
            if (multi) {
                var i = state.selected.indexOf(uid);
                if (i === -1) { state.selected.push(uid); } else { state.selected.splice(i, 1); }
            } else {
                state.selected = [uid];
            }
            sync();
            if (typeof o.onChange === 'function') { o.onChange(state.selected.slice()); }
        }
        q.options.forEach(function (opt, i) {
            var b = h('button', {
                type: 'button', class: 'trp-opt', role: multi ? 'checkbox' : 'radio', 'aria-checked': 'false', dataset: { uid: opt.uid },
                on: {
                    click: function () { choose(opt.uid); },
                    keydown: function (e) {
                        if (multi) { return; }
                        var k = e.key;
                        if (k === 'ArrowDown' || k === 'ArrowRight' || k === 'ArrowUp' || k === 'ArrowLeft') {
                            e.preventDefault();
                            var dir = (k === 'ArrowDown' || k === 'ArrowRight') ? 1 : -1;
                            var nx = buttons[(i + dir + buttons.length) % buttons.length];
                            nx.focus({ preventScroll: true });
                            choose(nx.dataset.uid);
                        }
                    }
                }
            }, [
                h('span', { class: 'trp-opt__letter', 'aria-hidden': 'true', text: LETTERS[i] || '' }),
                h('span', { class: 'trp-opt__text', text: opt.text || '' }),
                h('span', { class: 'trp-opt__mark trp-opt__mark--' + (multi ? 'box' : 'dot'), 'aria-hidden': 'true' }, multi ? icon('fa-check') : null)
            ]);
            buttons.push(b);
            list.appendChild(b);
        });
        sync();
        var card = h('div', { class: 'trp-qcard' + (o.compact ? ' trp-qcard--compact' : '') }, [
            h('div', { class: 'trp-qcard__kicker', text: kicker }),
            h('h2', { class: 'trp-qcard__text', id: qid, text: q.text || '' }),
            q.image_url ? h('div', { class: 'trp-qcard__img' }, h('img', { src: q.image_url, alt: '', loading: 'lazy' })) : null,
            list
        ]);
        return {
            el: card,
            choose: function (index) { var b = buttons[index]; if (b) { choose(b.dataset.uid); b.focus({ preventScroll: true }); } },
            focusFirst: function () { var b = buttons.filter(function (x) { return x.tabIndex === 0; })[0] || buttons[0]; if (b) { b.focus({ preventScroll: true }); } }
        };
    }

    // ------------------------------------------------------------------------------------------
    // TrainingPlayer.mount
    // ------------------------------------------------------------------------------------------
    function mount(root, view, adapter) {
        adapter = adapter || {};
        var lang = view.lang || 'en';
        var t = makeT(view.strings || {}, lang);
        var isKiosk = adapter.mode === 'kiosk';
        var canGrade = adapter.canGrade !== undefined ? !!adapter.canGrade : !!view.can_grade;
        var lessons = view.lessons || [];
        var byUid = {};
        lessons.forEach(function (l) { byUid[l.uid] = l; });
        var order = (view.lesson_order || []).filter(function (u) { return byUid[u]; });
        lessons.forEach(function (l) { if (order.indexOf(l.uid) === -1) { order.push(l.uid); } });
        var sectionOf = {};
        (view.sections || []).forEach(function (s, i) { (s.lesson_uids || []).forEach(function (u) { sectionOf[u] = { s: s, n: i + 1 }; }); });

        var progress = normaliseProgress(adapter.initialProgress);
        var cleanup = [];
        // Video options for this page: remembered through adapter.mediaPrefs, else for the page only.
        var MC = window.TrainingMediaControls || null;
        var memVol = MC ? MC.prefs.volume() : { level: 0.8, muted: false };
        var memCc = false;
        var ccLangPick = null;   // a caption language the learner picked on this page (EN | ES)
        var prefs = (function () {
            var p = adapter.mediaPrefs && typeof adapter.mediaPrefs === 'object' ? adapter.mediaPrefs : null;
            function has(n) { return !!(p && typeof p[n] === 'function'); }
            return {
                volume: function () {
                    var v = null;
                    try { v = has('getVolume') ? p.getVolume() : null; } catch (e) { v = null; }
                    return v && typeof v.level === 'number' && isFinite(v.level) ? { level: Math.max(0, Math.min(1, v.level)), muted: !!v.muted } : memVol;
                },
                setVolume: function (level, muted) {
                    memVol = { level: level, muted: !!muted };
                    if (has('setVolume')) { try { p.setVolume(level, !!muted); } catch (e) { /* ignore */ } }
                },
                cc: function () {
                    var c = null;
                    try { c = has('getCc') ? p.getCc() : null; } catch (e) { c = null; }
                    return typeof c === 'boolean' ? c : memCc;
                },
                setCc: function (on) {
                    memCc = !!on;
                    if (has('setCc')) { try { p.setCc(!!on); } catch (e) { /* ignore */ } }
                }
            };
        }());
        function langLabel(lg) { var k = 'lang_' + lg; var s = t(k); return s === k ? String(lg).toUpperCase() : s; }
        var keyHandler = null;
        // Kiosk (P3 §7.6): the run must exist before any lesson opens; the adapter says when it does.
        var runReady = typeof adapter.ensureRun !== 'function';
        var runPending = null;

        function fn(name) { return typeof adapter[name] === 'function' ? adapter[name] : null; }
        function errText(e) { return (e && e.message) ? String(e.message) : t('video_error'); }
        function mergeDone(map) {
            if (!map || typeof map !== 'object') { return; }
            Object.keys(map).forEach(function (u) { if (map[u] && byUid[u]) { progress.done[u] = true; } });
        }
        function allRequiredDone() { return order.every(function (u) { return progress.done[u] || !byUid[u].required; }); }
        function isFrozen() { return isKiosk && fn('runFrozen') ? !!adapter.runFrozen() : false; }
        /** The quick check of a content lesson (quiz role 'check' on an article, document, video or image), or null. */
        function checkOf(l) { return l && l.type !== 'quiz' && l.quiz && l.quiz.role === 'check' ? l.quiz : null; }
        /** Kiosk: the server's tries for a quiz or quick check ({used, max, left, locked, passed}); preview: null. */
        function checkInfo(uid) { var i = isKiosk && fn('quizInfo') ? adapter.quizInfo(uid) : null; return i && typeof i === 'object' ? i : null; }
        /** The lesson's own content is done (credited): its quick check can be taken. */
        function isCredited(uid) { return !!(progress.done[uid] || progress.credited[uid]); }
        /** A must-pass quick check still to pass: the content is done, the lesson is not. */
        function checkPending(uid) { var q = checkOf(byUid[uid]); return !!(q && q.must_pass && isCredited(uid) && !progress.done[uid]); }
        /** The quick check can be started now: content done, not passed, not locked, a try left (or one to resume). */
        function checkAvailable(uid) {
            if (!checkOf(byUid[uid]) || !isCredited(uid)) { return false; }
            var i = checkInfo(uid);
            return !(i && (i.passed || i.locked || (typeof i.left === 'number' && i.left <= 0 && !i.open)));
        }
        /** A done lesson whose quick check is optional and still open (skipped or not passed yet): "take it later". */
        function optionalCheckOpen(uid) { var q = checkOf(byUid[uid]); return !!(q && !q.must_pass && progress.done[uid] && checkAvailable(uid)); }
        /** Kiosk: the run waits for the sign-off, and a done lesson's optional quick check can still be taken. */
        function checkWhileSigning(uid) { return isKiosk && fn('checkWhileFrozen') && !!adapter.checkWhileFrozen() && optionalCheckOpen(uid); }
        function mergeCredited(map) {
            if (!map || typeof map !== 'object') { return; }
            Object.keys(map).forEach(function (u) { if (map[u] && byUid[u]) { progress.credited[u] = true; } });
        }
        /** A dismissible message at the top of the current screen (errors from the adapter). */
        function flash(msg, tone) {
            var old = screen.querySelector('.trp-flash');
            if (old && old.parentNode) { old.parentNode.removeChild(old); }
            var close = h('button', { type: 'button', class: 'trp-flash__close', 'aria-label': t('close') }, icon('fa-times'));
            var box = h('div', { class: 'trp-flash trp-flash--' + (tone || 'bad'), role: 'alert' }, [
                icon(tone === 'info' ? 'fa-info-circle' : 'fa-exclamation-triangle'), h('span', { class: 'trp-flash__text', text: msg }), close
            ]);
            close.addEventListener('click', function () { if (box.parentNode) { box.parentNode.removeChild(box); } });
            screen.insertBefore(box, screen.firstChild);
            setTimeout(function () { if (box.parentNode) { box.parentNode.removeChild(box); } }, 9000);
        }
        function setBusy(btn, on) {
            if (!btn) { return; }
            btn.disabled = !!on;
            btn.classList.toggle('is-busy', !!on);
            if (on) { btn.setAttribute('aria-busy', 'true'); } else { btn.removeAttribute('aria-busy'); }
        }
        /** Runs fn once the run exists (kiosk ensureRun), else at once. */
        function withRun(btn, then) {
            if (runReady) { then(); return; }
            setBusy(btn, true);
            if (!runPending) {
                runPending = Promise.resolve().then(function () { return adapter.ensureRun(); });
            }
            runPending.then(function (st) {
                runPending = null;
                runReady = true;
                if (st && st.credited) { mergeCredited(st.credited); }
                if (st && st.done) { mergeDone(st.done); saveProgress(); }
                setBusy(btn, false);
                then();
            }, function (e) {
                runPending = null;
                setBusy(btn, false);
                if (e && e.silent) { return; }   // the adapter's own dialog was closed: nothing to report
                renderHome();
                flash(errText(e));
            });
        }
        function finishCourse(res) {
            if (fn('onCourseComplete')) { adapter.onCourseComplete(res || null); return; }
            renderComplete();
        }

        function normaliseProgress(p) {
            p = (p && typeof p === 'object') ? p : {};
            return { done: Object.assign({}, p.done || {}), credited: Object.assign({}, p.credited || {}), pages: Object.assign({}, p.pages || {}),
                watch: Object.assign({}, p.watch || {}), tries: Object.assign({}, p.tries || {}), pos: Object.assign({}, p.pos || {}), current: p.current || null };
        }
        function saveProgress() {
            if (typeof adapter.onProgress === 'function') {
                try { adapter.onProgress(JSON.parse(JSON.stringify(progress))); } catch (e) { /* ignore */ }
            }
        }
        function runCleanup() {
            cleanup.splice(0).forEach(function (fn) { try { fn(); } catch (e) { /* ignore */ } });
            if (keyHandler) { document.removeEventListener('keydown', keyHandler); keyHandler = null; }
        }
        function onKeys(fn) {
            keyHandler = function (e) {
                if (!root.isConnected) { return; }
                var tag = (e.target && e.target.tagName) || '';
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) { return; }
                fn(e);
            };
            document.addEventListener('keydown', keyHandler);
        }

        /**
         * Kiosk: the final exam waits for every other required lesson (server rule exam_locked,
         * RunRepo::examBlocker). In a sequential course a lesson placed AFTER the exam (an
         * acknowledgment, say) is gated behind the exam, so it is not counted and the two never lock
         * each other; in a free-order course every other required lesson counts.
         */
        function examWaits(uid) {
            var l = byUid[uid];
            if (!isKiosk || !l || !l.quiz || l.quiz.role !== 'exam' || progress.done[uid]) { return false; }
            var idx = order.indexOf(uid);
            var seq = !!view.course.sequential;
            return order.some(function (u, i) { return u !== uid && (!seq || i < idx) && byUid[u] && byUid[u].required && !progress.done[u]; });
        }
        function isLocked(uid) {
            var l = byUid[uid];
            if (examWaits(uid)) { return true; }
            if (!l || !view.course.sequential || l.preview_enabled || progress.done[uid]) { return false; }
            var idx = order.indexOf(uid);
            for (var i = 0; i < idx; i++) {
                var prev = byUid[order[i]];
                if (prev && prev.required && !progress.done[prev.uid]) { return true; }
            }
            return false;
        }
        function doneCount() { return order.filter(function (u) { return progress.done[u]; }).length; }
        function nextUid(after) {
            var idx = after ? order.indexOf(after) : -1;
            for (var i = idx + 1; i < order.length; i++) { if (!progress.done[order[i]]) { return order[i]; } }
            return null;
        }
        function firstOpen() {
            for (var i = 0; i < order.length; i++) { if (!progress.done[order[i]]) { return order[i]; } }
            return null;
        }
        function exam() { return lessons.filter(function (l) { return l.quiz && l.quiz.role === 'exam'; })[0] || null; }
        function typeLabel(l) {
            if (l.type === 'quiz' && l.quiz) { return l.quiz.role === 'exam' ? t('t_exam') : t('t_quiz'); }
            return t('t_' + l.type);
        }
        /** noCheck: the row shows the quick check as its own chip instead. */
        function lessonSub(l, noCheck) {
            var parts = [typeLabel(l)];
            if (l.type === 'video' && l.duration_s) { parts.push(fmt(l.duration_s)); }
            else if (l.type === 'article') { parts.push(t('min_read', { n: minutes(l.duration_s) })); }
            else if (l.type === 'document' && l.document) { parts.push(l.document.page_count === 1 ? t('page_1') : t('pages_n', { n: l.document.page_count })); }
            else if (l.type === 'quiz' && l.quiz) {
                parts.push(l.quiz.question_count === 1 ? t('question_1') : t('questions_n', { n: l.quiz.question_count }));
                if (l.quiz.role === 'exam') { parts.push(t('pass_pct', { pct: l.quiz.pass_pct })); }
            } else if (l.type === 'acknowledgment') { parts.push(t('read_and_sign')); }
            else if (l.type === 'image') { parts.push(t('minutes', { n: minutes(l.duration_s || 60) })); }
            if (checkOf(l) && !noCheck) { parts.push(t('qc_chip')); }
            return parts.join(' · ');
        }

        // ---------------- chrome ----------------
        clear(root);
        root.classList.add('trp');
        root.setAttribute('lang', lang);
        var top = h('header', { class: 'trp-top' }, [
            h('div', { class: 'trp-top__brand' }, [
                h('span', { class: 'trp-top__mark', 'aria-hidden': 'true' }, icon('fa-hard-hat')),
                h('span', { class: 'trp-top__name' }, [adapter.brand ? (adapter.brand + ' ') : '', h('span', { class: 'trp-top__accent', text: 'Training' })])
            ]),
            h('div', { class: 'trp-top__right' }, [
                (view.languages || []).length > 1 ? h('div', { class: 'trp-seg', role: 'group', 'aria-label': t('language') }, view.languages.map(function (lg) {
                    return h('button', {
                        type: 'button', class: 'trp-seg__btn' + (lg === lang ? ' is-on' : ''), 'aria-pressed': lg === lang ? 'true' : 'false', lang: lg,
                        text: lg.toUpperCase(), on: { click: function () { if (lg !== lang && typeof adapter.onLanguage === 'function') { adapter.onLanguage(lg); } } }
                    });
                })) : null,
                h('div', { class: 'trp-top__who' }, [
                    h('span', { class: 'trp-avatar', 'aria-hidden': 'true', text: initials(adapter.learnerName || t('learner')) }),
                    h('span', { class: 'trp-top__whoname', text: adapter.learnerName || t('learner') })
                ])
            ])
        ]);
        var screen = h('div', { class: 'trp-screen' });
        if (adapter.chrome !== false) { root.appendChild(top); } else { root.classList.add('trp--nochrome'); }
        if (isKiosk) { root.classList.add('trp--kiosk'); }
        root.appendChild(screen);

        function initials(name) {
            return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || 'PL';
        }

        // ---------------- course home ----------------
        function renderHome() {
            runCleanup();
            progress.current = null;
            clear(screen);
            screen.scrollTop = 0;
            var c = view.course || {};
            var total = order.length;
            var done = doneCount();
            var pct = total ? Math.round(done * 100 / total) : 0;
            var next = firstOpen();
            var ex = exam();

            var cover = h('div', { class: 'trp-hero__cover' + (c.cover_url ? ' has-art' : ''), style: c.color ? { '--trp-course': c.color } : undefined });
            if (c.cover_url) { cover.appendChild(h('img', { src: c.cover_url, alt: '' })); }
            else { cover.appendChild(h('span', { class: 'trp-hero__glyph', 'aria-hidden': 'true' }, icon(c.kind === 'document' ? 'fa-file-signature' : 'fa-hard-hat'))); }

            var meta = [
                h('span', null, [icon('fa-list-ul'), total === 1 ? t('lesson_1') : t('lessons_n', { n: total })]),
                view.course.sequential ? h('span', null, [icon('fa-sort-numeric-down'), t('in_order')]) : null
            ];
            if (ex && ex.quiz) {
                meta.push(h('span', null, [icon('fa-graduation-cap'), t('t_exam') + ' · ' + (ex.quiz.question_count === 1 ? t('question_1') : t('questions_n', { n: ex.quiz.question_count }))]));
                meta.push(h('span', null, [icon('fa-bullseye'), t('pass_pct', { pct: ex.quiz.pass_pct })]));
                meta.push(h('span', null, [icon('fa-redo'), ex.quiz.max_attempts === 0 ? t('unlimited') : (ex.quiz.max_attempts === 1 ? t('attempts_1') : t('attempts_n', { n: ex.quiz.max_attempts }))]));
            }
            var ctaOverride = isKiosk && fn('homeCta') ? adapter.homeCta() : null;
            var cta;
            if (ctaOverride && ctaOverride.hidden) {
                cta = null;
            } else if (ctaOverride) {
                cta = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--xl', disabled: !!ctaOverride.disabled }, [
                    ctaOverride.icon && ctaOverride.iconFirst ? icon(ctaOverride.icon) : null, String(ctaOverride.label || ''),
                    ctaOverride.icon && !ctaOverride.iconFirst ? icon(ctaOverride.icon) : null
                ]);
                if (typeof ctaOverride.onClick === 'function') { cta.addEventListener('click', function () { ctaOverride.onClick(cta); }); }
            } else {
                cta = h('button', {
                    type: 'button', class: 'trp-btn trp-btn--primary trp-btn--xl', disabled: total === 0,
                    on: { click: function () { withRun(cta, function () { var u = firstOpen() || order[0]; if (u) { openLesson(u, checkPending(u) ? { check: true } : null); } }); } }
                }, [next && checkPending(next) ? t('qc_take_to', { title: byUid[next].title })   // watched/read: its must-pass quick check is next
                    : (next && (done > 0 || order.some(function (u) { return progress.credited[u]; })) ? t('continue_to', { title: byUid[next].title })
                    : (next ? t('start_course') : t('review_course'))), icon('fa-arrow-right')]);
            }
            var extraChips = isKiosk && fn('courseChips') ? (adapter.courseChips() || []) : [];
            var subline = isKiosk && fn('homeSubline') ? adapter.homeSubline() : null;
            var notice = isKiosk && fn('homeNotice') ? adapter.homeNotice() : null;

            var hero = h('section', { class: 'trp-hero' }, [
                cover,
                h('div', { class: 'trp-hero__body' }, [
                    h('div', { class: 'trp-chips' }, [
                        isKiosk && c.kind !== 'document' ? null
                            : h('span', { class: 'trp-chip trp-chip--accent' }, [icon(c.kind === 'document' ? 'fa-file-signature' : 'fa-shield-alt'), c.kind === 'document' ? t('t_document') : t('course_name_label')]),
                        c.est_minutes ? h('span', { class: 'trp-chip' }, [icon('fa-clock'), t('minutes_total', { n: c.est_minutes })]) : null
                    ].concat(extraChips.map(function (ch) {
                        var tone = /^(warn|bad|ok|info)$/.test(ch && ch.tone || '') ? ch.tone : '';
                        return h('span', { class: 'trp-chip' + (tone ? ' trp-chip--' + tone : '') }, [/^fa-[a-z0-9-]+$/.test(ch.icon || '') ? icon(ch.icon) : null, String(ch.text || '')]);
                    }))),
                    h('h1', { class: 'trp-hero__title', text: c.name || '' }),
                    c.summary ? h('p', { class: 'trp-hero__summary', text: c.summary }) : null,
                    h('div', { class: 'trp-meta' }, meta),
                    h('div', { class: 'trp-hero__progress' }, [
                        h('div', { class: 'trp-hero__bar' }, [
                            h('div', { class: 'trp-hero__barhead' }, [h('strong', { text: t('lessons_done', { n: done, m: total }) }), h('span', { class: 'trp-mono', text: pct + '%' })]),
                            h('div', { class: 'trp-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(pct), 'aria-label': t('lessons_done', { n: done, m: total }) },
                                h('span', { class: 'trp-bar__fill', style: { width: pct + '%' } }))
                        ]),
                        cta
                    ]),
                    subline ? h('p', { class: 'trp-hero__subline trp-muted', text: String(subline) }) : null
                ])
            ]);
            var noticeEl = null;
            if (notice && (notice.title || notice.text)) {
                var tone = /^(warn|bad|ok|info)$/.test(notice.tone || '') ? notice.tone : 'info';
                noticeEl = h('section', { class: 'trp-knotice trp-knotice--' + tone, role: 'status' }, [
                    h('span', { class: 'trp-knotice__icon', 'aria-hidden': 'true' }, icon(/^fa-[a-z0-9-]+$/.test(notice.icon || '') ? notice.icon : 'fa-info-circle')),
                    h('div', { class: 'trp-knotice__body' }, [
                        notice.title ? h('strong', { class: 'trp-knotice__title', text: String(notice.title) }) : null,
                        notice.text ? h('span', { class: 'trp-knotice__text', text: String(notice.text) }) : null
                    ])
                ]);
            }
            // Kiosk: a way back to the Learning Center that does not sign the person out (Done does).
            var hb = isKiosk && fn('homeBack') ? adapter.homeBack() : null;
            var backRow = null;
            if (hb && hb.label) {
                var backBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--back' }, [icon('fa-arrow-left'), String(hb.label)]);
                if (typeof hb.onClick === 'function') { backBtn.addEventListener('click', function () { setBusy(backBtn, true); hb.onClick(); }); }
                backRow = h('div', { class: 'trp-homeback' }, backBtn);
            }
            screen.appendChild(h('div', { class: 'trp-page' }, [backRow, hero, noticeEl, curriculum(), c.description_html ? aboutCard(c.description_html) : null]));
        }

        function aboutCard(html) {
            return h('section', { class: 'trp-card trp-about' }, [
                h('h2', { class: 'trp-card__title', text: t('about_course') }),
                setTrustedHtml(h('div', { class: 'trp-article trp-article--compact' }), html)
            ]);
        }

        function curriculum() {
            var card = h('section', { class: 'trp-card trp-curr' });
            var sections = view.sections || [];
            var total = order.length;
            card.appendChild(h('header', { class: 'trp-curr__head' }, [
                h('h2', { class: 'trp-card__title', text: t('course_content') }),
                h('span', { class: 'trp-muted', text: (sections.length ? (sections.length === 1 ? t('section_1') : t('sections_n', { n: sections.length })) + ' · ' : '') + (total === 1 ? t('lesson_1') : t('lessons_n', { n: total })) })
            ]));
            var loose = order.filter(function (u) { return !sectionOf[u]; });
            if (loose.length) { card.appendChild(sectionBlock(null, loose, 0)); }
            sections.forEach(function (s, i) {
                var uids = (s.lesson_uids || []).filter(function (u) { return byUid[u]; });
                if (uids.length) { card.appendChild(sectionBlock(s, uids, i + 1)); }
            });
            if (!total) { card.appendChild(h('p', { class: 'trp-muted trp-curr__empty', text: t('lessons_n', { n: 0 }) })); }
            return card;
        }

        function sectionBlock(s, uids, n) {
            var done = uids.filter(function (u) { return progress.done[u]; }).length;
            var locked = uids.every(function (u) { return isLocked(u); });
            var pill = null;
            if (done === uids.length) { pill = h('span', { class: 'trp-pill trp-pill--ok' }, [icon('fa-check'), t('n_of_m_done', { n: done, m: uids.length })]); }
            else if (locked) { pill = h('span', { class: 'trp-pill trp-pill--muted' }, [icon('fa-lock'), t('locked_chip')]); }
            else if (done > 0 || uids.indexOf(progress.current) !== -1 || uids.some(function (u) { return progress.credited[u]; })) { pill = h('span', { class: 'trp-pill trp-pill--info' }, [icon('fa-circle-notch'), t('in_progress') + ' · ' + t('n_of_m_done', { n: done, m: uids.length })]); }
            var block = h('div', { class: 'trp-sec' }, [
                s ? h('div', { class: 'trp-sec__head' }, [
                    h('span', { class: 'trp-sec__num', text: t('section_n', { n: n }) }),
                    h('h3', { class: 'trp-sec__title', text: s.title || '' }),
                    pill
                ]) : null
            ]);
            var list = h('ol', { class: 'trp-lessons' });
            uids.forEach(function (u) { list.appendChild(lessonRow(byUid[u])); });
            block.appendChild(list);
            return block;
        }

        function lessonRow(l) {
            var frozen = isFrozen();
            var locked = frozen || isLocked(l.uid);
            var done = !!progress.done[l.uid];
            var next = !frozen && firstOpen() === l.uid;
            var pendingCheck = !frozen && !done && checkPending(l.uid);
            // Waiting for the sign-off: a done lesson's optional quick check can still be taken ("later").
            var signCheck = frozen && done && checkWhileSigning(l.uid);
            // Kiosk, frozen (locked, blocked...): the adapter says why a lesson cannot open ("Locked · see your trainer").
            var frozenLabel = frozen && isKiosk && fn('frozenRowLabel') ? adapter.frozenRowLabel() : null;
            var stateEl;
            if (done) {
                stateEl = [h('span', { class: 'trp-row__state trp-row__state--ok', text: t('done') }), h('span', { class: 'trp-round trp-round--ok', 'aria-hidden': 'true' }, icon('fa-check'))];
            } else if (pendingCheck && !locked) {
                stateEl = [h('span', { class: 'trp-pill trp-pill--info', text: t('qc_pending') }), h('span', { class: 'trp-round trp-round--primary', 'aria-hidden': 'true' }, icon('fa-clipboard-check'))];
            } else if (locked) {
                stateEl = [h('span', { class: 'trp-row__state', text: typeof frozenLabel === 'string' && frozenLabel ? frozenLabel : (!frozen && examWaits(l.uid) ? t('exam_after') : t('locked')) }),
                    h('span', { class: 'trp-round trp-round--muted', 'aria-hidden': 'true' }, icon('fa-lock'))];
            } else if (next) {
                stateEl = [h('span', { class: 'trp-pill trp-pill--info', text: progress.current === l.uid || doneCount() > 0 ? t('in_progress') : t('start_chip') }),
                    h('span', { class: 'trp-round trp-round--primary', 'aria-hidden': 'true' }, icon('fa-play'))];
            } else {
                stateEl = [h('span', { class: 'trp-row__state', text: t('start_chip') }), h('span', { class: 'trp-round', 'aria-hidden': 'true' }, icon('fa-play'))];
            }
            var chips = [];
            if (!l.required) { chips.push(h('span', { class: 'trp-chip trp-chip--sm', text: t('optional_chip') })); }
            if (l.preview_enabled) { chips.push(h('span', { class: 'trp-chip trp-chip--sm', text: t('open_chip') })); }
            // A done lesson whose optional quick check was skipped or not passed: it can still be taken.
            var laterChip = optionalCheckOpen(l.uid) && (!frozen || signCheck);
            if (laterChip) { chips.push(h('span', { class: 'trp-chip trp-chip--sm trp-chip--check' }, [icon('fa-clipboard-check'), t('qc_optional_chip')])); }
            var rowLocked = locked && !(done && !frozen) && !signCheck;
            var btn = h('button', {
                type: 'button', class: 'trp-row' + (next && !done ? ' is-current' : '') + (rowLocked ? ' is-locked' : ''), 'aria-disabled': rowLocked ? 'true' : null,
                on: { click: function () { if (!rowLocked) { withRun(btn, function () { openLesson(l.uid, checkPending(l.uid) || signCheck ? { check: true } : null); }); } } }
            }, [
                h('span', { class: 'trp-tile trp-tile--' + l.type, 'aria-hidden': 'true' }, icon(TYPE_ICON[l.type] || 'fa-file')),
                h('span', { class: 'trp-row__main' }, [
                    h('span', { class: 'trp-row__title', text: l.title || typeLabel(l) }),
                    h('span', { class: 'trp-row__sub' }, [lessonSub(l, laterChip)].concat(chips))
                ]),
                h('span', { class: 'trp-row__end' }, stateEl)
            ]);
            return h('li', null, btn);
        }

        // ---------------- lesson frame ----------------
        /** opts.check: the lesson's quick check (intro, then the quiz runner) instead of its content. */
        function openLesson(uid, opts) {
            var l = byUid[uid];
            if (!l) { renderHome(); return; }
            if (!runReady) { withRun(null, function () { openLesson(uid, opts); }); return; }
            runCleanup();
            progress.current = uid;
            saveProgress();
            var live = true;
            cleanup.push(function () { live = false; });
            var chk = checkOf(l);
            var checkMode = !!(opts && opts.check) && !!chk;
            var openP = null;
            if (!checkMode && typeof adapter.onLessonOpen === 'function') { try { openP = adapter.onLessonOpen(uid); } catch (e) { openP = null; } }
            // Kiosk: the server gate (lesson_open / lesson_tick) decides when "Mark complete" unlocks (§7.6 #3, #4).
            var kGate = isKiosk && openP && typeof openP.then === 'function';
            clear(screen);
            screen.scrollTop = 0;
            var idx = order.indexOf(uid);
            var sec = sectionOf[uid];
            var ticks = h('div', { class: 'trp-ticks', 'aria-hidden': 'true' }, order.map(function (u) {
                return h('span', { class: 'trp-ticks__t' + (progress.done[u] ? ' is-done' : '') + (u === uid ? ' is-current' : '') });
            }));
            var headRight = h('div', { class: 'trp-lhead__right' }, [
                h('div', { class: 'trp-lhead__count' }, [h('strong', { text: t('lesson_n_of', { n: idx + 1, total: order.length }) }), ticks])
            ]);
            var head = h('header', { class: 'trp-lhead' }, [
                h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--back', on: { click: renderHome } }, [icon('fa-arrow-left'), t('course_home')]),
                h('div', { class: 'trp-lhead__titles' }, [
                    h('div', { class: 'trp-lhead__crumb', text: (view.course.name || '') + (sec ? ' · ' + t('section_n', { n: sec.n }) : '') }),
                    h('h1', { class: 'trp-lhead__title', text: l.title || typeLabel(l) })
                ]),
                headRight
            ]);
            var body = h('div', { class: 'trp-lbody' });
            var main = h('div', { class: 'trp-lmain' });
            var aside = h('aside', { class: 'trp-laside' });
            body.appendChild(main);
            var footStatus = h('div', { class: 'trp-foot__status', role: 'status', 'aria-live': 'polite' });
            var nextBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost', on: { click: goNext } }, [t('next_lesson'), icon('fa-arrow-right')]);
            var completeBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--primary', on: { click: function () { complete({}); } } }, [
                icon('fa-check'), t('mark_complete') + (isKiosk ? '' : ' ' + t('preview_suffix'))
            ]);
            var foot = h('footer', { class: 'trp-foot' }, [footStatus, h('div', { class: 'trp-foot__actions' }, [nextBtn, completeBtn])]);
            var wrap = h('div', { class: 'trp-lesson trp-lesson--' + (checkMode ? 'check' : l.type) }, [head, h('div', { class: 'trp-lscroll' }, [
                l.description_html && l.type !== 'quiz' && !checkMode ? setTrustedHtml(h('div', { class: 'trp-article trp-article--desc' }), l.description_html) : null,
                body
            ]), foot]);
            screen.appendChild(wrap);

            // The gate. Preview: the renderer's own rule (never blocking). Kiosk: the renderer supplies the
            // wording and a local percentage; the server gate (null until lesson_open answers) decides.
            var server = null;
            var serverOff = false;   // quiz lessons: lesson_open returns {quiz:true} and no gate
            var footErr = null;
            var gate = {
                met: true, text: '', pct: null, cMet: true, cText: '', cPct: null, cPlain: false,
                // plain: the text is the whole instruction (acknowledgment to-do), shown as is with a pen icon.
                set: function (met, text, pct, plain) { gate.cMet = met; gate.cText = text; gate.cPct = pct; gate.cPlain = !!plain; gate.render(); },
                server: function (g) {
                    if (!g || typeof g !== 'object') { return; }
                    if (g.quiz) { serverOff = true; gate.render(); return; }
                    server = g;
                    if (g.done) { progress.done[uid] = true; }
                    if (g.credited) { progress.credited[uid] = true; }
                    if (typeof syncCheckUi === 'function') { syncCheckUi(); }
                    if (ctx && typeof ctx.onServerGate === 'function') { try { ctx.onServerGate(g); } catch (e) { /* ignore */ } }
                    gate.render();
                },
                render: function () {
                    var met = gate.cMet;
                    var text = gate.cText;
                    var pct = gate.cPct;
                    var pending = false;
                    if (kGate && !serverOff) {
                        if (server === null) {
                            met = false;
                            pending = true;
                        } else if (server.done) {
                            met = true;
                            pct = 100;
                        } else {
                            met = !!server.can_complete;
                            var sp = serverPct(server);
                            pct = gate.cPct === null && sp === null ? null : Math.max(0, Math.min(99, sp === null ? gate.cPct : sp));
                            if (met) { pct = 100; }
                            if (!met && gate.cMet) {
                                var left = Math.max(0, Number(server.required_s || 0) - Number(server.credit_s || 0));
                                text = left > 0 ? null : gate.cText;
                                if (left > 0) { text = '\u0000' + t('k_keep_going', { t: fmt(left) }); }
                            }
                        }
                    }
                    var serverMet = met;
                    // A renderer with its own to-do (the acknowledgment: tick, sign, PIN) keeps saying what
                    // is left until those steps are done, whatever the server gate says.
                    if (isKiosk && kGate && !serverOff && !pending && gate.cPlain && !gate.cMet) { met = false; text = gate.cText; pct = null; }
                    gate.met = met; gate.text = text; gate.pct = pct;
                    clear(footStatus);
                    var plainNow = gate.cPlain && !pending && text === gate.cText;
                    footStatus.appendChild(h('span', { class: 'trp-round trp-round--sm' + (met ? ' trp-round--ok' : ' trp-round--muted'), 'aria-hidden': 'true' },
                        pending ? icon('fa-circle-notch', 'fa-spin') : icon(met ? 'fa-check' : (plainNow ? 'fa-pen-nib' : 'fa-lock'))));
                    var label;
                    if (pending) { label = t('k_checking'); }
                    else if (met) { label = t('ready_to_finish'); }
                    else if (typeof text === 'string' && text.charAt(0) === '\u0000') { label = text.slice(1); }
                    else if (plainNow) { label = text; }
                    else { label = isKiosk ? t('available_after', { req: text }) : t('preview_gate', { req: text }); }
                    var over = !pending && ctx && typeof ctx.footLabel === 'function' ? ctx.footLabel(met) : null;
                    if (typeof over === 'string' && over) { label = over; }
                    var col = h('div', { class: 'trp-foot__text' }, [h('span', { text: label })]);
                    if (typeof pct === 'number') {
                        col.appendChild(h('span', { class: 'trp-bar trp-bar--sm' + (met ? ' is-ok' : '') }, h('span', { class: 'trp-bar__fill', style: { width: Math.max(0, Math.min(100, pct)) + '%' } })));
                    }
                    if (footErr) { col.appendChild(h('span', { class: 'trp-foot__err', role: 'alert' }, [icon('fa-exclamation-triangle'), h('span', { text: footErr })])); }
                    footStatus.appendChild(col);
                    // Preview never traps the author: the button stays usable and the hint shows the rule.
                    var blocked = isKiosk && !met;
                    if (!completeBtn.classList.contains('is-busy')) { completeBtn.disabled = blocked; }
                    completeBtn.classList.toggle('is-soft', !met);
                    if (ctx && typeof ctx.onGate === 'function') { try { ctx.onGate(serverMet); } catch (e) { /* ignore */ } }
                    if (typeof syncNextBtn === 'function') { syncNextBtn(); }
                },
                error: function (msg) { footErr = msg || null; gate.render(); }
            };
            /**
             * "Next lesson" shows only when it would open something. Kiosk: a next lesson that is still
             * locked (an in-order course, before this one is credited) hides the button, so the only
             * way on is the lesson's own finish button; it comes back once this lesson is done.
             */
            function syncNextBtn() {
                var n = order[idx + 1];
                nextBtn.hidden = !n || (isKiosk && isLocked(n));
                if (ctx && ctx.noNext) { nextBtn.hidden = true; }
            }
            function serverPct(g) {
                var parts = [];
                if (Number(g.required_s) > 0) { parts.push(Number(g.credit_s || 0) * 100 / Number(g.required_s)); }
                if (l.type === 'video' && Number(g.duration_s) > 0) {
                    var need = Math.max(1, Number(g.duration_s) * Number(g.min_watch_pct || 0) / 100 - 5);
                    parts.push(Number(g.max_position_s || 0) * 100 / need);
                }
                if (l.type === 'document' && Number(g.page_count) > 0) { parts.push(Number(g.pages_seen || 0) * 100 / Number(g.page_count)); }
                if (!parts.length) { return null; }
                return Math.round(Math.min.apply(null, parts.map(function (x) { return Math.min(100, x); })));
            }
            gate.set(true, '');
            syncNextBtn();
            if (progress.done[uid]) { completeBtn.hidden = true; nextBtn.classList.add('trp-btn--primary'); nextBtn.classList.remove('trp-btn--ghost'); }

            // Quick check: once the content is done the lesson offers its check - as the finish button while a
            // must-pass check is still to pass, and as an extra button on a done lesson whose check is open.
            var checkBtn = null;
            if (chk && !checkMode) {
                checkBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost', hidden: true, on: { click: function () { openLesson(uid, { check: true }); } } },
                    [icon('fa-clipboard-check'), t('qc_take')]);
                foot.querySelector('.trp-foot__actions').insertBefore(checkBtn, nextBtn);
            }
            function syncCheckUi() {
                if (!chk || checkMode) { return; }
                // Content done, must-pass check to pass: "Take the quick check". Before that the finish button
                // already says where it goes: "Continue to quick check" (the check opens right after the content).
                var pendingNow = checkPending(uid);
                clear(completeBtn);
                completeBtn.appendChild(icon('fa-clipboard-check'));
                completeBtn.appendChild(document.createTextNode(pendingNow ? t('qc_take') : t('qc_continue') + (isKiosk ? '' : ' ' + t('preview_suffix'))));
                if (checkBtn) { checkBtn.hidden = !(progress.done[uid] && checkAvailable(uid)); }
            }
            syncCheckUi();

            // ---- kiosk ticks (§7.6 #4): 15 s while visible (article, document, image, ack), 10 s while an
            //      uploaded video plays plus one on every play/pause; the response drives the gate.
            var tickState = { pages: [], lastInteraction: Date.now(), timer: null, first: null, seq: 0, sent: 0 };
            function interacted() { tickState.lastInteraction = Date.now(); }
            function sampleNow(extra) {
                var s = {
                    playing: false,
                    visible: document.visibilityState !== 'hidden',
                    active: Date.now() - tickState.lastInteraction < 60000,
                    pages_seen: tickState.pages.splice(0)
                };
                if (ctx && typeof ctx.tickSample === 'function') {
                    var x = ctx.tickSample() || {};
                    Object.keys(x).forEach(function (k) { s[k] = x[k]; });
                }
                if (extra) { Object.keys(extra).forEach(function (k) { s[k] = extra[k]; }); }
                if (!s.pages_seen.length) { delete s.pages_seen; }
                return s;
            }
            function sendTick(extra) {
                if (!live || !isKiosk || !fn('onTick') || serverOff || isCredited(uid)) { return Promise.resolve(null); }
                var mySeq = ++tickState.seq;
                var s = sampleNow(extra);
                tickState.sent = Date.now();
                return Promise.resolve().then(function () { return adapter.onTick(uid, s); }).then(function (g) {
                    if (live && mySeq === tickState.seq && g) { gate.server(g); }
                    return g;
                }, function (e) {
                    if (s.pages_seen) { Array.prototype.push.apply(tickState.pages, s.pages_seen); }   // resend next time
                    if (live && e && (e.code === 'run_locked' || e.code === 'run_blocked' || e.code === 'video_changed')) { gate.error(errText(e)); }
                    return null;
                });
            }
            function startTicks() {
                if (!isKiosk || !fn('onTick') || serverOff || isCredited(uid)) { return; }
                var evs = ['pointerdown', 'keydown', 'wheel', 'touchstart'];
                evs.forEach(function (ev) { root.addEventListener(ev, interacted, { passive: true }); });
                var scroller = wrap.querySelector('.trp-lscroll');
                if (scroller) { scroller.addEventListener('scroll', interacted, { passive: true }); }
                var onVis = function () { sendTick(); };
                document.addEventListener('visibilitychange', onVis);
                var isVideo = l.type === 'video';
                if (!isVideo) {
                    // First credit after ~6 s (image and acknowledgment need 5 s), then every 15 s while visible.
                    tickState.first = setTimeout(function () { if (document.visibilityState !== 'hidden') { sendTick(); } }, 6000);
                    tickState.timer = setInterval(function () { if (document.visibilityState !== 'hidden') { sendTick(); } }, 15000);
                } else {
                    tickState.timer = setInterval(function () {
                        var st = typeof ctx.tickSample === 'function' ? ctx.tickSample() : null;
                        if (st && st.playing) { sendTick(); }
                    }, 10000);
                }
                cleanup.push(function () {
                    clearTimeout(tickState.first);
                    clearInterval(tickState.timer);
                    evs.forEach(function (ev) { root.removeEventListener(ev, interacted, { passive: true }); });
                    if (scroller) { scroller.removeEventListener('scroll', interacted, { passive: true }); }
                    document.removeEventListener('visibilitychange', onVis);
                });
                sendTick();   // one immediately after the open
            }

            function complete(evidence) {
                if (checkPending(uid)) { openLesson(uid, { check: true }); return; }   // content done already: on to the check
                if (isKiosk && !gate.met) { return; }
                if (!isKiosk) {
                    progress.done[uid] = true;
                    progress.credited[uid] = true;
                    saveProgress();
                    var p = typeof adapter.onLessonComplete === 'function' ? adapter.onLessonComplete(uid, evidence || {}) : null;
                    var after = function () { if (chk) { openLesson(uid, { check: true }); } else { goNext(); } };
                    Promise.resolve(p).then(after, after);
                    return;
                }
                // Kiosk (§7.6 #5): done only after the server says so; a refusal keeps the learner here.
                if (completeBtn.classList.contains('is-busy')) { return; }
                var ev = evidence || {};
                if (typeof ctx.evidence === 'function') {
                    var x = ctx.evidence() || {};
                    Object.keys(x).forEach(function (k) { if (ev[k] === undefined) { ev[k] = x[k]; } });
                }
                if (tickState.pages.length && !ev.pages_seen) { ev.pages_seen = tickState.pages.splice(0); }
                setBusy(completeBtn, true);
                gate.error(null);
                Promise.resolve().then(function () { return fn('onLessonComplete') ? adapter.onLessonComplete(uid, ev) : null; }).then(function (res) {
                    if (!live) { return; }
                    progress.credited[uid] = true;
                    if (!(chk && chk.must_pass)) { progress.done[uid] = true; }   // a must-pass check: done once the server says so (after a pass)
                    if (res && res.credited) { mergeCredited(res.credited); }
                    if (res && res.done) { mergeDone(res.done); }
                    if (chk && res && res.check && fn('onCheckState')) { try { adapter.onCheckState(uid, res.check); } catch (x) { /* ignore */ } }
                    saveProgress();
                    setBusy(completeBtn, false);
                    if (chk && checkAvailable(uid)) { openLesson(uid, { check: true }); return; }   // the quick check comes right after
                    goNext();
                }, function (e) {
                    if (!live) { return; }
                    setBusy(completeBtn, false);
                    if (e && e.data && typeof e.data === 'object' && 'can_complete' in e.data) { gate.server(e.data); }
                    gate.error(errText(e));
                });
            }
            function goNext() {
                var n = order[idx + 1];
                if (isKiosk && n && isLocked(n) && !progress.done[uid]) { gate.error(t('k_finish_first')); return; }
                if (n && !isLocked(n) && !(isKiosk && progress.done[n] && !nextUid(uid) && allRequiredDone())) { openLesson(n); return; }
                if (!nextUid(null) && allRequiredDone()) { finishCourse(); return; }
                if (isKiosk && allRequiredDone()) { finishCourse(); return; }
                renderHome();
            }

            var ctx = { lesson: l, main: main, aside: aside, body: body, gate: gate, complete: complete, foot: foot, head: head, headRight: headRight, wrap: wrap,
                goNext: goNext, live: function () { return live; }, checkMode: checkMode };
            ctx.sendTick = sendTick;
            ctx.pageSeen = function (n) { if (tickState.pages.indexOf(n) === -1) { tickState.pages.push(n); } };
            if (chk && !checkMode) {
                // Ready to finish a lesson with a quick check: the status line says the check comes next.
                ctx.footLabel = function (met) {
                    if (!met || progress.done[uid]) { return null; }
                    if (checkPending(uid)) { return t('qc_foot_must'); }
                    var n = Number(chk.question_count) || 0;
                    return chk.must_pass ? (n === 1 ? t('qc_then_must_1') : t('qc_then_must_n', { n: n })) : (n === 1 ? t('qc_then_1') : t('qc_then_n', { n: n }));
                };
            }
            var renderers = { article: renderArticle, document: renderDocument, video: renderVideo, image: renderImage, acknowledgment: renderAck, quiz: renderQuizIntro };
            (checkMode ? renderCheckIntro : (renderers[l.type] || renderArticle))(ctx);
            var res = resourcesCard(l);
            if (res) { aside.appendChild(res); }
            var upNext = ctx.noUpNext ? null : upNextCard(idx);
            if (upNext) { aside.appendChild(upNext); }
            if (aside.childNodes.length) { body.appendChild(aside); body.classList.add('has-aside'); }
            onKeys(function (e) {
                if (e.key === 'Escape' && !root.querySelector('.trp-lightbox')) { renderHome(); }
                if (typeof ctx.onKey === 'function') { ctx.onKey(e); }
            });
            var focusTarget = head.querySelector('.trp-lhead__title');
            if (focusTarget) { focusTarget.tabIndex = -1; focusTarget.focus({ preventScroll: true }); }
            if (kGate) {
                gate.render();
                openP.then(function (g) {
                    if (!live) { return; }
                    gate.server(g || {});
                    if (server === null && !serverOff) { server = { can_complete: false }; gate.render(); }
                    startTicks();
                }, function (e) {
                    if (!live) { return; }
                    renderHome();
                    flash(errText(e));
                });
            }
        }

        function resourcesCard(l) {
            var list = l.resources || [];
            if (!list.length) { return null; }
            return h('section', { class: 'trp-card trp-side' }, [
                h('header', { class: 'trp-side__head' }, [h('h2', { class: 'trp-card__title', text: t('resources') }), h('span', { class: 'trp-muted', text: list.length === 1 ? t('resources_1') : t('resources_n', { n: list.length }) })]),
                h('ul', { class: 'trp-res' }, list.map(function (r) {
                    var link = r.kind === 'link';
                    return h('li', null, h('a', { class: 'trp-res__item', href: r.url, target: '_blank', rel: 'noopener noreferrer' }, [
                        h('span', { class: 'trp-tile trp-tile--' + (link ? 'article' : 'document'), 'aria-hidden': 'true' }, icon(link ? 'fa-link' : 'fa-file-download')),
                        h('span', { class: 'trp-res__title', text: r.title }),
                        h('span', { class: 'trp-round trp-round--sm', 'aria-hidden': 'true' }, icon(link ? 'fa-external-link-alt' : 'fa-download')),
                        h('span', { class: 'visually-hidden', text: link ? t('open_link') : t('download') })
                    ]));
                }))
            ]);
        }

        function upNextCard(idx) {
            var n = byUid[order[idx + 1]];
            if (!n) { return null; }
            return h('section', { class: 'trp-card trp-upnext' }, [
                h('span', { class: 'trp-tile trp-tile--' + n.type, 'aria-hidden': 'true' }, icon(TYPE_ICON[n.type] || 'fa-file')),
                h('div', null, [h('div', { class: 'trp-kicker', text: t('up_next') }), h('div', { class: 'trp-upnext__title', text: n.title }), h('div', { class: 'trp-muted trp-upnext__sub', text: lessonSub(n) })])
            ]);
        }

        // ---------------- article ----------------
        function renderArticle(ctx) {
            var a = ctx.lesson.article || {};
            ctx.main.appendChild(h('div', { class: 'trp-reading' }, setTrustedHtml(h('article', { class: 'trp-article' }), a.body_html || '')));
        }

        // ---------------- document ----------------
        function renderDocument(ctx) {
            var l = ctx.lesson;
            var d = l.document || { pages: [], page_count: 0 };
            var pages = d.pages || [];
            var viewed = {};
            (progress.pages[l.uid] || []).forEach(function (n) { viewed[n] = true; });
            var cur = 0;
            var zoom = 1;
            if (d.download_url) {
                ctx.headRight.appendChild(h('a', { class: 'trp-btn trp-btn--ghost', href: d.download_url, rel: 'noopener' }, [icon('fa-download'), t('download')]));
            }
            if (!pages.length) {
                ctx.main.appendChild(h('div', { class: 'trp-card trp-empty-note', text: t('pages_n', { n: 0 }) }));
                return;
            }
            var img = h('img', { class: 'trp-doc__page', alt: '' });
            var pageBox = h('div', { class: 'trp-doc__viewport' }, img);
            var prev = h('button', { type: 'button', class: 'trp-doc__nav trp-doc__nav--prev', 'aria-label': t('previous'), on: { click: function () { go(cur - 1, true); } } }, icon('fa-chevron-left'));
            var next = h('button', { type: 'button', class: 'trp-doc__nav trp-doc__nav--next', 'aria-label': t('next'), on: { click: function () { go(cur + 1, true); } } }, icon('fa-chevron-right'));
            var zoomOut = h('button', { type: 'button', class: 'trp-round trp-round--btn', 'aria-label': t('zoom_out'), on: { click: function () { setZoom(zoom - 0.5); } } }, icon('fa-search-minus'));
            var zoomIn = h('button', { type: 'button', class: 'trp-round trp-round--btn', 'aria-label': t('zoom_in'), on: { click: function () { setZoom(zoom + 0.5); } } }, icon('fa-search-plus'));
            var hint = h('span', { class: 'trp-doc__hint' }, [icon(isKiosk && mouseOnly() ? 'fa-arrows-alt-h' : 'fa-search-plus'), isKiosk && mouseOnly() ? t('k_arrows_hint') : t('swipe_hint')]);
            var hintTimer = setTimeout(function () { hint.classList.add('is-faded'); }, 3000);
            cleanup.push(function () { clearTimeout(hintTimer); });
            var stage = h('div', { class: 'trp-doc__stage' }, [
                hint,
                h('div', { class: 'trp-doc__zoom' }, [zoomOut, zoomIn]),
                pageBox, prev, next
            ]);
            var label = h('strong', { class: 'trp-doc__label' });
            var viewedLabel = h('span', { class: 'trp-muted' });
            var thumbs = h('div', { class: 'trp-doc__thumbs', role: 'list' });
            var thumbBtns = pages.map(function (p, i) {
                var b = h('button', { type: 'button', class: 'trp-doc__thumb', role: 'listitem', 'aria-label': t('page_of', { n: p.n, total: pages.length }), on: { click: function () { go(i, true); } } }, [
                    h('img', { src: p.url, alt: '', loading: 'lazy' }), h('span', { class: 'trp-doc__thumbn', text: String(p.n) }), h('span', { class: 'trp-doc__thumbok', 'aria-hidden': 'true' }, icon('fa-check'))
                ]);
                thumbs.appendChild(b);
                return b;
            });
            // Pick up where you left off: the first page not seen yet (preview: progress.pages; kiosk: the run's
            // credited pages in the first gate). "Back to page 1" undoes it.
            var moved = false;
            var docResume = MC ? MC.resumeBar({ labels: { start_over: t('back_to_first') }, onStartOver: function () { moved = true; go(0); } }) : null;
            ctx.main.appendChild(h('div', { class: 'trp-doc' }, [docResume ? docResume.el : null, stage, h('div', { class: 'trp-doc__bar' }, [h('div', { class: 'trp-doc__labels' }, [label, viewedLabel]), thumbs])]));
            function firstUnseen() {
                for (var i = 0; i < pages.length; i++) { if (!viewed[pages[i].n]) { return i; } }
                return -1;
            }
            function resumeDoc() {
                var i = firstUnseen();
                if (moved || i <= 0 || !docResume) { return false; }
                go(i);
                docResume.show(t('resume_page', { n: pages[i].n }));
                return true;
            }

            function setZoom(z) {
                zoom = Math.max(1, Math.min(3, z));
                pageBox.classList.toggle('is-zoomed', zoom > 1);
                img.style.setProperty('--trp-zoom', String(zoom));
                zoomOut.disabled = zoom <= 1;
                zoomIn.disabled = zoom >= 3;
            }
            function go(i, byLearner) {
                if (i < 0 || i >= pages.length) { return; }
                if (byLearner) { moved = true; if (docResume) { docResume.hide(); } }
                if (i !== cur) { hint.classList.add('is-faded'); }
                cur = i;
                var p = pages[i];
                img.src = p.url;
                img.alt = t('page_of', { n: p.n, total: pages.length });
                if (p.w && p.h) { img.width = p.w; img.height = p.h; }
                viewed[p.n] = true;
                if (typeof ctx.pageSeen === 'function') { ctx.pageSeen(p.n); }
                progress.pages[l.uid] = Object.keys(viewed).map(Number);
                saveProgress();
                prev.disabled = i === 0;
                next.disabled = i === pages.length - 1;
                label.textContent = t('page_of', { n: p.n, total: pages.length });
                var nViewed = Object.keys(viewed).length;
                viewedLabel.textContent = t('pages_viewed', { n: nViewed, total: pages.length });
                thumbBtns.forEach(function (b, j) {
                    b.classList.toggle('is-current', j === i);
                    b.classList.toggle('is-viewed', !!viewed[pages[j].n]);
                    if (j === i) { b.setAttribute('aria-current', 'page'); } else { b.removeAttribute('aria-current'); }
                });
                var cb = thumbBtns[i];
                if (cb) {
                    // Only the strip scrolls (scrollIntoView would also scroll the lesson).
                    var left = cb.offsetLeft - thumbs.offsetLeft;
                    if (left < thumbs.scrollLeft) { thumbs.scrollLeft = left - 8; }
                    else if (left + cb.offsetWidth > thumbs.scrollLeft + thumbs.clientWidth) { thumbs.scrollLeft = left + cb.offsetWidth - thumbs.clientWidth + 8; }
                }
                var all = nViewed >= pages.length;
                ctx.gate.set(all, t('req_pages', { n: pages.length }), Math.round(nViewed * 100 / pages.length));
                setZoom(1);
            }
            ctx.onKey = function (e) {
                if (e.key === 'ArrowLeft') { go(cur - 1, true); }
                if (e.key === 'ArrowRight') { go(cur + 1, true); }
            };
            // Swipe (pointer) to turn pages; ignored while zoomed (then the page pans).
            var sx = null;
            pageBox.addEventListener('pointerdown', function (e) { sx = zoom > 1 ? null : e.clientX; });
            pageBox.addEventListener('pointerup', function (e) {
                if (sx === null) { return; }
                var dx = e.clientX - sx;
                sx = null;
                if (Math.abs(dx) > 50) { go(cur + (dx < 0 ? 1 : -1), true); }
            });
            if (isKiosk) {
                // The run's credited pages arrive with the first gate (lesson_open): mark them, then pick up there.
                var docFirstGate = true;
                ctx.onServerGate = function (g) {
                    if (!docFirstGate || !g) { return; }
                    docFirstGate = false;
                    if (g.done || g.credited || !Array.isArray(g.pages_seen_list) || !g.pages_seen_list.length) { return; }
                    g.pages_seen_list.forEach(function (n) { n = Number(n); if (n >= 1 && n <= pages.length) { viewed[n] = true; } });
                    progress.pages[l.uid] = Object.keys(viewed).map(Number);
                    if (!resumeDoc()) { go(cur); }
                };
            }
            // Preview: pages viewed in an earlier visit (progress.pages) - measured before page 1 is shown now.
            var hadViewed = !isKiosk && firstUnseen() > 0;
            go(0);
            if (hadViewed) { resumeDoc(); }
        }

        // ---------------- video ----------------
        function renderVideo(ctx) {
            var l = ctx.lesson;
            var v = l.video;
            if (!v) { ctx.main.appendChild(h('div', { class: 'trp-card trp-empty-note', text: t('video_error') })); return; }
            var minPct = typeof v.min_watch_pct === 'number' ? v.min_watch_pct : 90;
            var duration = v.duration_s || 0;
            var maxWatched = Number(progress.watch[l.uid] || 0);
            var ringBox = h('div', { class: 'trp-watch__ring' });
            var ringText = h('div', { class: 'trp-watch__text' });
            var watchCard = h('section', { class: 'trp-card trp-side trp-watch' }, [
                h('header', { class: 'trp-side__head' }, [h('h2', { class: 'trp-card__title', text: t('watch_progress') })]),
                h('div', { class: 'trp-watch__body' }, [ringBox, ringText]),
                h('div', { class: 'trp-note' }, [icon('fa-info-circle'), h('div', null, [h('strong', { text: t('skip_not_counted') }), h('div', { text: t('only_watched') })])])
            ]);
            ctx.aside.appendChild(watchCard);
            var status = h('div', { class: 'trp-vstatus', role: 'status', 'aria-live': 'polite' });

            function watchedPct() { return duration > 0 ? Math.min(100, Math.round(maxWatched * 100 / duration)) : 0; }
            function updateWatch() {
                var p = watchedPct();
                clear(ringBox);
                ringBox.appendChild(ring(p, p >= minPct ? 'trp-ring--ok' : '', p + '%'));
                ringBox.appendChild(h('span', { class: 'trp-ring__label', text: p + '%' }));
                clear(ringText);
                ringText.appendChild(h('strong', { text: p >= minPct ? t(checkOf(l) && !progress.done[l.uid] ? 'qc_watched' : 'watched_enough', { pct: p }) : t('keep_watching', { pct: p }) }));
                if (duration > 0 && p < 100) { ringText.appendChild(h('div', { class: 'trp-muted', text: t('about_left', { t: fmt(Math.max(0, duration - maxWatched)) }) })); }
                ctx.gate.set(p >= minPct, t('req_watch', { pct: minPct }), p);
            }
            function record(cur) {
                if (cur > maxWatched) {
                    maxWatched = cur;
                    progress.watch[l.uid] = Math.round(maxWatched);
                    saveProgress();
                }
            }

            if (v.provider === 'upload') {
                var video = h('video', { class: 'trp-video__el', preload: 'metadata', playsinline: true, src: v.src_url });
                var playBtn = h('button', { type: 'button', class: 'trp-vbtn trp-vbtn--play', 'aria-label': t('play') }, icon('fa-play'));
                var backBtn = h('button', { type: 'button', class: 'trp-vbtn trp-vbtn--wide' }, [icon('fa-undo'), t('back_10')]);
                var fsBtn = h('button', { type: 'button', class: 'trp-vbtn', 'aria-label': t('fullscreen') }, icon('fa-expand'));
                var fill = h('span', { class: 'trp-scrub__fill' });
                var maxEl = h('span', { class: 'trp-scrub__max' });
                var knob = h('span', { class: 'trp-scrub__knob' });
                var scrub = h('div', { class: 'trp-scrub', role: 'slider', tabindex: '0', 'aria-label': t('furthest'), 'aria-valuemin': '0' }, [maxEl, fill, knob]);
                var timeEl = h('span', { class: 'trp-vtime trp-mono' });
                var bigPlay = h('button', { type: 'button', class: 'trp-video__bigplay', 'aria-label': t('play') }, icon('fa-play'));

                // ---- video options: captions (the files the author attached), volume, resume
                var cues = MC ? MC.cueBox() : null;
                var tt = MC && Array.isArray(v.captions) && v.captions.length
                    ? MC.textTracks(video, v.captions, { labelOf: langLabel, onCue: function (lines) { if (cues) { cues.show(lines); } } }) : null;
                var hasCc = !!(tt && tt.langs.length);
                var ccOn = hasCc && prefs.cc();
                // The course language being taken comes first (LearnerView orders the list); a pick on this page wins.
                var ccLang = hasCc ? (ccLangPick && tt.langs.indexOf(ccLangPick) !== -1 ? ccLangPick : tt.langs[0]) : null;
                var ccSwitch = null;
                var syncCc = function () {
                    if (tt) { ccLang = tt.setMode(ccOn, ccLang) || ccLang; }
                    if (ccSwitch) { ccSwitch.set(ccLang); ccSwitch.show(ccOn); }
                    if (!ccOn && cues) { cues.clear(); }
                };
                var ccBtn = hasCc ? MC.ccButton({ labels: { cc: t('cc'), cc_short: t('cc_short'), none: t('cc_none') }, on: ccOn,
                    onToggle: function (on) { ccOn = on; prefs.setCc(on); syncCc(); } }) : null;
                if (ccBtn) { ccBtn.available('yes'); }
                if (hasCc && tt.langs.length > 1) {
                    ccSwitch = MC.langSwitch({ langs: tt.langs, current: ccLang, labelOf: langLabel, label: t('cc_lang'),
                        onPick: function (lg) { ccLang = lg; ccLangPick = lg; syncCc(); } });
                }
                var vp = prefs.volume();
                var applyVol = function (level, muted) {
                    try { video.volume = Math.max(0, Math.min(1, level)); } catch (e) { /* iOS: read-only */ }
                    video.muted = !!muted;
                };
                var vol = MC ? MC.volume({ labels: { mute: t('vol_mute'), unmute: t('vol_unmute'), volume: t('volume') }, level: vp.level, muted: vp.muted,
                    onChange: function (level, muted) { applyVol(level, muted); prefs.setVolume(level, muted); } }) : null;
                applyVol(vp.level, vp.muted);
                var resumeAt = null;
                var resumeOffered = false;
                var resume = MC ? MC.resumeBar({ labels: { start_over: t('start_over') }, onStartOver: function () {
                    resumeAt = null;
                    try { video.currentTime = 0; } catch (e) { /* ignore */ }
                    if (!isKiosk) { progress.pos[l.uid] = 0; saveProgress(); }
                    syncUi();
                } }) : null;

                var box = h('div', { class: 'trp-video' }, [
                    h('div', { class: 'trp-video__stage' }, [video, cues ? cues.el : null, bigPlay, h('span', { class: 'trp-video__badge' }, [icon('fa-film'), t('video_note_upload')])]),
                    resume ? resume.el : null,
                    h('div', { class: 'trp-vcontrols' }, [playBtn, backBtn, h('div', { class: 'trp-scrub__wrap' }, [scrub, h('span', { class: 'trp-scrub__cap', text: t('furthest') })]), timeEl,
                        ccBtn || ccSwitch || vol ? h('div', { class: 'trp-vopts' }, [ccBtn ? ccBtn.el : null, ccSwitch ? ccSwitch.el : null, vol ? vol.el : null]) : null, fsBtn])
                ]);
                ctx.main.appendChild(box);
                ctx.main.appendChild(status);
                var syncUi = function () {
                    var d = duration || video.duration || 0;
                    var cur = video.currentTime || 0;
                    var pctCur = d ? (cur * 100 / d) : 0;
                    var pctMax = d ? (maxWatched * 100 / d) : 0;
                    fill.style.width = pctCur + '%';
                    knob.style.left = pctCur + '%';
                    maxEl.style.width = Math.min(100, pctMax) + '%';
                    timeEl.textContent = fmt(cur) + ' / ' + fmt(d);
                    scrub.setAttribute('aria-valuemax', String(Math.round(d)));
                    scrub.setAttribute('aria-valuenow', String(Math.round(cur)));
                    var playing = !video.paused && !video.ended;
                    bigPlay.hidden = playing;
                    playBtn.setAttribute('aria-label', playing ? t('pause') : t('play'));
                    clear(playBtn);
                    playBtn.appendChild(icon(playing ? 'fa-pause' : 'fa-play'));
                };
                /**
                 * Pick up where you left off: opens at $sec (never past the furthest point watched) with
                 * "Resuming at 3:42 · Start over". Only before the learner has started this video here, and
                 * not near either end.
                 */
                var offerResume = function (sec) {
                    sec = Math.floor(Number(sec) || 0);
                    var d = duration || video.duration || 0;
                    if (!resume || resumeOffered || sec < 5 || (d > 0 && sec >= d - 3) || !video.paused || (video.currentTime || 0) > 1) { return; }
                    resumeOffered = true;
                    resumeAt = Math.min(sec, Math.floor(maxWatched) || sec);
                    resume.show(t('resume_at', { t: fmt(resumeAt) }));
                    var go = function () {
                        if (resumeAt === null) { return; }
                        try { video.currentTime = resumeAt; } catch (e) { /* ignore */ }
                        syncUi();
                    };
                    if (video.readyState >= 1) { go(); } else { video.addEventListener('loadedmetadata', go, { once: true }); }
                };
                var lastPosSave = 0;
                var savePos = function (force) {
                    if (isKiosk) { return; }   // the kiosk resumes from the run (server), never from the device
                    var now = Date.now();
                    if (!force && now - lastPosSave < 4000) { return; }
                    lastPosSave = now;
                    progress.pos[l.uid] = video.ended ? 0 : Math.floor(video.currentTime || 0);
                    saveProgress();
                };
                video.addEventListener('loadedmetadata', function () { if (!duration && video.duration && isFinite(video.duration)) { duration = video.duration; } updateWatch(); syncUi(); });
                video.addEventListener('timeupdate', function () {
                    // No seeking past the furthest point watched (UX only; the kiosk server rules are the control).
                    if (video.currentTime > maxWatched + 2) { video.currentTime = maxWatched; return; }
                    record(video.currentTime);
                    updateWatch();
                    syncUi();
                    if (resumeAt !== null && resume && resume.shown() && video.currentTime > resumeAt + 8) { resume.hide(); }
                    if (!video.paused) { savePos(false); }
                });
                video.addEventListener('play', syncUi);
                video.addEventListener('pause', function () { syncUi(); savePos(true); });
                video.addEventListener('ended', function () { record(duration || video.duration || 0); updateWatch(); syncUi(); if (resume) { resume.hide(); } savePos(true); });
                video.addEventListener('ratechange', function () { if (video.playbackRate !== 1) { video.playbackRate = 1; video.pause(); } });
                video.addEventListener('error', function () {
                    clear(status);
                    status.className = 'trp-vstatus is-error';
                    status.appendChild(icon('fa-exclamation-triangle'));
                    status.appendChild(h('span', { text: t('video_error') }));
                });
                playBtn.addEventListener('click', function () { if (video.paused) { video.play().catch(function () { /* blocked or unsupported */ }); } else { video.pause(); } });
                bigPlay.addEventListener('click', function () { video.play().catch(function () { /* blocked or unsupported */ }); });
                backBtn.addEventListener('click', function () { video.currentTime = Math.max(0, video.currentTime - 10); });
                fsBtn.addEventListener('click', function () { var st = box.querySelector('.trp-video__stage'); if (st && st.requestFullscreen) { st.requestFullscreen().catch(function () { /* ignore */ }); } });
                function seekFromEvent(clientX) {
                    var r = scrub.getBoundingClientRect();
                    var d = duration || video.duration || 0;
                    if (!d || !r.width) { return; }
                    var target = Math.max(0, Math.min(1, (clientX - r.left) / r.width)) * d;
                    video.currentTime = Math.min(target, maxWatched);
                }
                scrub.addEventListener('click', function (e) { seekFromEvent(e.clientX); });
                scrub.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowLeft') { video.currentTime = Math.max(0, video.currentTime - 5); e.preventDefault(); }
                    if (e.key === 'ArrowRight') { video.currentTime = Math.min(maxWatched, video.currentTime + 5); e.preventDefault(); }
                });
                ctx.onKey = function (e) {
                    if (e.key === ' ' && e.target === document.body) { e.preventDefault(); playBtn.click(); }
                    // Windows keyboard: Up / Down = volume +-10 % (a no-op on iOS, where only Mute works).
                    if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && vol && !e.altKey && !e.ctrlKey && !e.metaKey && vol.step(e.key === 'ArrowUp' ? 0.1 : -0.1)) { e.preventDefault(); }
                };
                syncCc();
                if (isKiosk) {
                    // Kiosk (§7.6 #4): a tick on every play/pause, every 10 s while playing (the tick timer asks
                    // tickSample), and the server's furthest point drives the seek limit. The first gate (lesson_open)
                    // also says where this person stopped: the run's furthest point for this lesson.
                    ctx.tickSample = function () { return { position_s: Math.floor(video.currentTime || 0), playing: !video.paused && !video.ended }; };
                    ctx.evidence = function () { return { position_s: Math.floor(video.currentTime || 0) }; };
                    var firstGate = true;
                    ctx.onServerGate = function (g) {
                        var mp = Number(g && g.max_position_s || 0);
                        if (mp > maxWatched) { maxWatched = mp; updateWatch(); syncUi(); }
                        if (firstGate) {
                            firstGate = false;
                            if (g && !g.done && !g.credited) { offerResume(mp); }
                        }
                    };
                    ['play', 'pause', 'ended'].forEach(function (ev) { video.addEventListener(ev, function () { if (ctx.sendTick) { ctx.sendTick(); } }); });
                } else {
                    offerResume(Math.min(Number(progress.pos[l.uid] || 0), maxWatched || 0));
                }
                cleanup.push(function () { if (tt) { tt.destroy(); } try { video.pause(); video.removeAttribute('src'); video.load(); } catch (e) { /* ignore */ } });
                updateWatch();
                syncUi();
                return;
            }

            // YouTube / Vimeo. Kiosk (§7.6 #7): they play on their own page (own CSP), so here only a card.
            if (isKiosk && fn('externalVideoUrl')) {
                var url = adapter.externalVideoUrl(l.uid);
                var progText = h('span', { class: 'trp-muted' });
                var progBar = h('span', { class: 'trp-bar__fill', style: { width: '0%' } });
                var setProg = function (pos) {
                    var d = duration || 0;
                    var p = d > 0 ? Math.min(100, Math.round(pos * 100 / d)) : 0;
                    progBar.style.width = p + '%';
                    progText.textContent = t('keep_watching', { pct: p });
                    if (p >= minPct) { progText.textContent = t(checkOf(l) && !progress.done[l.uid] ? 'qc_watched' : 'watched_enough', { pct: p }); }
                };
                ctx.onServerGate = function (g) {
                    if (g && Number(g.duration_s) > 0 && !duration) { duration = Number(g.duration_s); }
                    setProg(Number(g && g.max_position_s || 0));
                };
                var watchBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--xl' }, [icon('fa-play'), t('watch_video')]);
                watchBtn.addEventListener('click', function () { setBusy(watchBtn, true); location.assign(url); });
                ctx.main.appendChild(h('section', { class: 'trp-card trp-extvideo' }, [
                    h('div', { class: 'trp-extvideo__art', 'aria-hidden': 'true' }, h('i', { class: 'fab ' + (v.provider === 'vimeo' ? 'fa-vimeo-v' : 'fa-youtube') })),
                    h('div', { class: 'trp-extvideo__body' }, [
                        h('div', { class: 'trp-chips' }, [
                            h('span', { class: 'trp-chip' }, [h('i', { class: 'fab ' + (v.provider === 'vimeo' ? 'fa-vimeo-v' : 'fa-youtube'), 'aria-hidden': 'true' }), v.provider === 'vimeo' ? 'Vimeo' : 'YouTube']),
                            duration ? h('span', { class: 'trp-chip' }, [icon('fa-clock'), fmt(duration)]) : null
                        ]),
                        h('h2', { class: 'trp-extvideo__title', text: l.title || '' }),
                        h('p', { class: 'trp-muted', text: t('ext_video_note') }),
                        h('div', { class: 'trp-extvideo__prog' }, [h('span', { class: 'trp-bar' }, progBar), progText]),
                        watchBtn
                    ])
                ]));
                setProg(0);
                ctx.gate.set(false, t('req_watch', { pct: minPct }), 0);
                return;
            }
            var ext = parseExt(v);
            var holder = h('div', { class: 'trp-embed' });
            var verifiedChip = h('span', { class: 'trp-pill trp-pill--ok', hidden: !v.verified }, [icon('fa-check-circle'), t('verified')]);
            var tapNote = h('div', { class: 'trp-tapnote' }, [icon('fa-hand-pointer'), t('tap_to_start')]);
            var ePlay = h('button', { type: 'button', class: 'trp-vbtn trp-vbtn--play', 'aria-label': t('play') }, icon('fa-play'));
            var eBack = h('button', { type: 'button', class: 'trp-vbtn trp-vbtn--wide' }, [icon('fa-undo'), t('back_10')]);
            var eFill = h('span', { class: 'trp-scrub__max' });
            var eTime = h('span', { class: 'trp-vtime trp-mono' });
            var controller = null;
            // ---- video options through the provider's player: captions (CC), volume, resume
            var eCcOn = prefs.cc();
            var eCcNote = h('div', { class: 'trp-ccnote', hidden: true }, [icon('fa-closed-captioning'), h('span', { text: t('cc_none') })]);
            var eCc = MC ? MC.ccButton({ labels: { cc: t('cc'), cc_short: t('cc_short'), none: t('cc_none') }, on: eCcOn,
                onToggle: function (on) { eCcOn = on; prefs.setCc(on); if (controller) { controller.setCaptions(on, lang); } } }) : null;
            var evp = prefs.volume();
            var eVol = MC ? MC.volume({ labels: { mute: t('vol_mute'), unmute: t('vol_unmute'), volume: t('volume') }, level: evp.level, muted: evp.muted,
                onChange: function (level, muted) { prefs.setVolume(level, muted); if (controller) { controller.setVolume(level); controller.setMuted(muted); } } }) : null;
            var eResumeAt = null;
            var eResume = MC ? MC.resumeBar({ labels: { start_over: t('start_over') }, onStartOver: function () {
                var was = eResumeAt;
                eResumeAt = null;
                progress.pos[l.uid] = 0;
                saveProgress();
                if (was === -1 && controller) { controller.seekTo(0); }   // already jumped: back to the start
            } }) : null;
            ctx.main.appendChild(h('div', { class: 'trp-video trp-video--embed' }, [
                h('div', { class: 'trp-video__top' }, [h('span', { class: 'trp-chip' }, [h('i', { class: 'fab ' + (v.provider === 'vimeo' ? 'fa-vimeo-v' : 'fa-youtube'), 'aria-hidden': 'true' }), v.provider === 'vimeo' ? 'Vimeo' : 'YouTube']), tapNote, verifiedChip]),
                holder,
                eResume ? eResume.el : null,
                h('div', { class: 'trp-vcontrols' }, [ePlay, eBack, h('div', { class: 'trp-scrub__wrap' }, [h('div', { class: 'trp-scrub trp-scrub--static' }, eFill), h('span', { class: 'trp-scrub__cap', text: t('furthest') })]), eTime,
                    eCc || eVol ? h('div', { class: 'trp-vopts' }, [eCc ? eCc.el : null, eVol ? eVol.el : null]) : null]),
                eCcNote
            ]));
            ctx.main.appendChild(status);
            // Pick up where you left off (preview keeps the last position per lesson): the jump happens on the first
            // play, because YouTube / Vimeo start only from a tap inside their player.
            var ePos = Math.min(Number(progress.pos[l.uid] || 0), Number(progress.watch[l.uid] || 0));
            if (eResume && ePos >= 5 && (!duration || ePos < duration - 3)) {
                eResumeAt = Math.floor(ePos);
                eResume.show(t('resume_at', { t: fmt(eResumeAt) }));
            }
            var eLastSave = 0;
            function eSavePos(cur, force) {
                var now = Date.now();
                if (!force && now - eLastSave < 4000) { return; }
                eLastSave = now;
                progress.pos[l.uid] = Math.floor(cur || 0);
                saveProgress();
            }
            function syncEmbed(cur, d) {
                if (d > 0 && !duration) { duration = d; }
                var dd = duration || d || 0;
                eFill.style.width = (dd ? Math.min(100, maxWatched * 100 / dd) : 0) + '%';
                eTime.textContent = fmt(cur) + ' / ' + (dd ? fmt(dd) : '–:––');
            }
            function showError(code, message) {
                clear(status);
                status.className = 'trp-vstatus is-error';
                status.appendChild(icon('fa-exclamation-triangle'));
                status.appendChild(h('div', null, [h('strong', { text: t('video_error') }), h('div', { class: 'trp-muted', text: message || '' }), h('div', { class: 'trp-muted trp-mono', text: t('video_error_detail', { code: code }) })]));
                if (typeof adapter.onVideoError === 'function') {
                    try { adapter.onVideoError(l, lang, { provider: v.provider, extId: ext.id, extHash: ext.hash, code: code }); } catch (e) { /* ignore */ }
                }
            }
            if (!window.TrainingVideoEmbed) { showError('api_load_failed', ''); updateWatch(); return; }
            controller = window.TrainingVideoEmbed.mount(holder, {
                provider: v.provider, embedUrl: v.embed_url, title: l.title || 'Video',
                onPlaying: function (durationS) {
                    tapNote.hidden = true;
                    if (durationS > 0 && !duration) { duration = durationS; }
                    if (eResumeAt !== null && eResumeAt > 0 && controller) {
                        controller.seekTo(Math.min(eResumeAt, maxWatched || eResumeAt));
                        eResumeAt = -1;   // done; "Start over" now seeks back to 0
                        setTimeout(function () { if (eResume) { eResume.hide(); } }, 8000);
                    }
                    if (view.can_verify_video && !v.verified && typeof adapter.onVideoReady === 'function') {
                        clear(status);
                        status.className = 'trp-vstatus';
                        status.appendChild(icon('fa-circle-notch', 'fa-spin'));
                        status.appendChild(h('span', { text: t('verifying') }));
                        Promise.resolve(adapter.onVideoReady(l, lang, { provider: v.provider, extId: ext.id, extHash: ext.hash, durationS: durationS })).then(function (r) {
                            clear(status);
                            status.className = 'trp-vstatus';
                            if (r && r.verified) {
                                v.verified = true;
                                verifiedChip.hidden = false;
                                clear(verifiedChip);
                                verifiedChip.appendChild(icon('fa-check-circle'));
                                verifiedChip.appendChild(document.createTextNode(t('verified_at', { t: fmt(durationS) })));
                            }
                        }, function (err) {
                            clear(status);
                            status.className = 'trp-vstatus is-error';
                            status.appendChild(icon('fa-exclamation-triangle'));
                            status.appendChild(h('span', { text: (err && err.message) || t('video_error') }));
                        });
                    }
                },
                onState: function (s) {
                    var playing = s === 'playing';
                    clear(ePlay);
                    ePlay.appendChild(icon(playing ? 'fa-pause' : 'fa-play'));
                    ePlay.setAttribute('aria-label', playing ? t('pause') : t('play'));
                    if (s === 'ended' && controller) { record(controller.getDuration() || duration); updateWatch(); progress.pos[l.uid] = 0; saveProgress(); }
                    if (s === 'paused' && controller) { eSavePos(controller.getCurrentTime(), true); }
                },
                onTime: function (tm) {
                    // Seeking ahead is snapped back (UX only).
                    if (tm.current > maxWatched + 3 && controller) { controller.seekTo(maxWatched); return; }
                    record(tm.current);
                    updateWatch();
                    syncEmbed(tm.current, tm.duration);
                    if (controller && controller.isPlaying()) { eSavePos(tm.current, false); }
                },
                onCaptionTracks: function (list) {
                    // The provider says which captions the video has: none -> the CC button is off with a short note.
                    var none = !Array.isArray(list) || list.length === 0;
                    if (eCc) { eCc.available(none ? 'no' : 'yes'); }
                    eCcNote.hidden = !none;
                },
                onError: showError
            });
            if (eVol) { controller.setVolume(evp.level); controller.setMuted(evp.muted); }
            controller.setCaptions(eCcOn, lang);
            ctx.onKey = function (e) {
                if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && eVol && !e.altKey && !e.ctrlKey && !e.metaKey && eVol.step(e.key === 'ArrowUp' ? 0.1 : -0.1)) { e.preventDefault(); }
            };
            ePlay.addEventListener('click', function () { if (controller) { controller.toggle(); } });
            eBack.addEventListener('click', function () { if (controller) { controller.seekBy(-10); } });
            cleanup.push(function () { if (controller) { controller.destroy(); } });
            syncEmbed(0, duration);
            updateWatch();
        }

        function parseExt(v) {
            var id = v.video_id || '';
            var hash = null;
            try {
                var u = new URL(v.embed_url);
                if (v.provider === 'vimeo') { hash = u.searchParams.get('h'); }
            } catch (e) { hash = null; }
            return { id: id, hash: hash || null };
        }

        // ---------------- image ----------------
        function renderImage(ctx) {
            var im = ctx.lesson.image;
            if (!im) { ctx.main.appendChild(h('div', { class: 'trp-card trp-empty-note', text: t('t_image') })); return; }
            var img = h('img', { src: im.url, alt: im.caption || ctx.lesson.title || '', width: im.w || null, height: im.h || null });
            var btn = h('button', { type: 'button', class: 'trp-image__btn', 'aria-label': t('open_image'), on: { click: openBox } }, [img, h('span', { class: 'trp-image__zoom', 'aria-hidden': 'true' }, icon('fa-expand'))]);
            ctx.main.appendChild(h('figure', { class: 'trp-image' }, [btn, im.caption ? h('figcaption', { text: im.caption }) : null]));
            function openBox() {
                var close = h('button', { type: 'button', class: 'trp-lightbox__close', 'aria-label': t('close') }, icon('fa-times'));
                var box = h('div', { class: 'trp-lightbox', role: 'dialog', 'aria-modal': 'true', 'aria-label': ctx.lesson.title || t('t_image') }, [
                    h('img', { src: im.url, alt: im.caption || '' }), im.caption ? h('p', { class: 'trp-lightbox__cap', text: im.caption }) : null, close
                ]);
                function shut() { document.removeEventListener('keydown', onEsc, true); if (box.parentNode) { box.parentNode.removeChild(box); } btn.focus({ preventScroll: true }); }
                function onEsc(e) { if (e.key === 'Escape') { e.stopPropagation(); shut(); } }
                close.addEventListener('click', shut);
                box.addEventListener('click', function (e) { if (e.target === box) { shut(); } });
                document.addEventListener('keydown', onEsc, true);
                root.appendChild(box);
                close.focus({ preventScroll: true });
                cleanup.push(shut);
            }
        }

        // ---------------- acknowledgment ----------------
        function renderAck(ctx) {
            if (isKiosk && fn('signAck')) { renderAckKiosk(ctx); return; }
            var a = ctx.lesson.ack || { statement_html: '', require_signature: true, require_pin: true };
            var checked = false;
            var inked = !a.require_signature;
            var pin = '';
            var check = h('button', { type: 'button', class: 'trp-check', role: 'checkbox', 'aria-checked': 'false' }, [
                h('span', { class: 'trp-check__box', 'aria-hidden': 'true' }, icon('fa-check')), h('span', { text: t('ack_confirm') })
            ]);
            // Kiosk sign-off layout (approved mockup): statement, box and signature on the left, the
            // PIN card on the right, and one strong Sign button in the footer, always on screen.
            var signBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--lg trp-ack__sign' }, [icon('fa-pen-nib'), t('sign_continue')]);
            var parts = [
                h('section', { class: 'trp-card trp-ack__statement' }, [
                    h('div', { class: 'trp-kicker', text: t('ack_title') }),
                    setTrustedHtml(h('div', { class: 'trp-article trp-article--statement' }), a.statement_html || '')
                ]),
                check
            ];
            var pad = null;
            if (a.require_signature) {
                pad = signaturePad(function (ok) { inked = ok; sync(); });
                parts.push(pad.el);
            }
            var dots = null;
            if (a.require_pin) {
                dots = h('div', { class: 'trp-pin__dots', 'aria-live': 'polite' });
                var keys = h('div', { class: 'trp-pin__keys' });
                ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'].forEach(function (k) {
                    keys.appendChild(h('button', {
                        type: 'button', class: 'trp-pin__key' + (k.length > 1 ? ' trp-pin__key--fn' : ''), 'aria-label': k === 'clear' ? t('clear') : (k === 'back' ? t('back') : k),
                        on: { click: function () { press(k); } }
                    }, k === 'back' ? icon('fa-backspace') : (k === 'clear' ? t('clear') : k)));
                });
                ctx.aside.appendChild(h('section', { class: 'trp-card trp-pin' }, [
                    h('div', { class: 'trp-pin__head' }, [h('strong', { text: t('enter_pin') }), h('span', { class: 'trp-muted', text: t('pin_hint') })]),
                    dots, keys
                ]));
                ctx.noUpNext = true;
            }
            ctx.main.appendChild(h('div', { class: 'trp-ack' }, parts));
            var actions = ctx.foot.querySelector('.trp-foot__actions');
            actions.appendChild(signBtn);
            function press(k) {
                if (k === 'clear') { pin = ''; } else if (k === 'back') { pin = pin.slice(0, -1); } else if (pin.length < 6) { pin += k; }
                sync();
            }
            function sync() {
                check.setAttribute('aria-checked', checked ? 'true' : 'false');
                check.classList.toggle('is-on', checked);
                if (dots) {
                    clear(dots);
                    for (var i = 0; i < Math.max(4, pin.length); i++) { dots.appendChild(h('span', { class: 'trp-pin__dot' + (i < pin.length ? ' is-on' : '') })); }
                    dots.setAttribute('aria-label', pin.length + ' / 4');
                }
                var ok = checked && inked && (!a.require_pin || pin.length >= 4);
                signBtn.disabled = !ok;
                var todo = a.require_signature && a.require_pin ? 'ack_todo_all' : (a.require_signature ? 'ack_todo_sign' : (a.require_pin ? 'ack_todo_pin' : 'ack_todo_tick'));
                ctx.gate.set(ok, t(todo), null, true);
            }
            check.addEventListener('click', function () { checked = !checked; sync(); });
            signBtn.addEventListener('click', function () {
                if (signBtn.disabled) { return; }
                pin = '';   // never kept, never sent
                ctx.complete({ acknowledged: true, signed: !!a.require_signature });
            });
            ctx.foot.querySelector('.trp-foot__actions .trp-btn--primary').hidden = true;
            sync();
        }

        /**
         * Kiosk acknowledgment (§7.6 #6, #13): statement, "I understand", the kiosk's one signature pad
         * (adapter.signaturePad) and a 4-12 digit PIN keypad, always shown for a document course. The
         * PIN lives only in this closure and is wiped after every call.
         */
        function renderAckKiosk(ctx) {
            var l = ctx.lesson;
            var a = l.ack || { statement_html: '', require_signature: true, require_pin: true };
            var needPin = !!a.require_pin || (view.course && view.course.kind === 'document');
            var checked = false;
            var inked = !a.require_signature;
            var serverOk = false;
            var pin = '';
            var busy = false;
            var check = h('button', { type: 'button', class: 'trp-check', role: 'checkbox', 'aria-checked': 'false' }, [
                h('span', { class: 'trp-check__box', 'aria-hidden': 'true' }, icon('fa-check')), h('span', { text: t('ack_confirm') })
            ]);
            // The Sign button lives in the sticky footer (always on screen, like the mockup), in place of
            // Next / Mark complete; the footer status says what is left: tick, sign, PIN.
            var signBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--lg trp-ack__sign' }, [icon('fa-pen-nib'), t('sign_continue')]);
            // The last lesson of a course that is then signed off on sign.php: say this is step 1 of 2.
            var lastThenSign = order.indexOf(l.uid) === order.length - 1 && !(view.course && view.course.kind === 'document');
            var parts = [
                h('section', { class: 'trp-card trp-ack__statement' }, [
                    h('div', { class: 'trp-kicker', text: lastThenSign ? t('ack_title') + ' · ' + t('k_ack_step') : t('ack_title') }),
                    setTrustedHtml(h('div', { class: 'trp-article trp-article--statement' }), a.statement_html || '')
                ]),
                check
            ];
            var pad = null;
            if (a.require_signature) {
                var padHost = h('section', { class: 'trp-card trp-sign trp-sign--kiosk' });
                parts.push(padHost);
                var padOpts = {
                    title: t('sign_here'),
                    onChange: function (ok) { inked = !!ok; sync(); },
                    name: adapter.learnerName || '',
                    date: new Date().toLocaleDateString(lang === 'es' ? 'es' : 'en', { year: 'numeric', month: 'long', day: 'numeric' })
                };
                if (fn('signaturePad')) {
                    pad = adapter.signaturePad(padHost, padOpts);
                } else {
                    padHost.appendChild(h('div', { class: 'trp-sign__head' }, [h('strong', { text: t('sign_here') })]));
                    var own = signaturePad(function (ok) { inked = ok; sync(); });
                    padHost.appendChild(own.el);
                    pad = null;
                }
            }
            var dots = null;
            var keyBtns = [];
            if (needPin) {
                dots = h('div', { class: 'trp-pin__dots', 'aria-hidden': 'true' });
                var keys = h('div', { class: 'trp-pin__keys', role: 'group', 'aria-label': t('enter_pin') });
                ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'].forEach(function (k) {
                    var b = h('button', {
                        type: 'button', class: 'trp-pin__key' + (k.length > 1 ? ' trp-pin__key--fn' : ''), 'aria-label': k === 'clear' ? t('clear') : (k === 'back' ? t('back') : k),
                        on: { click: function () { press(k); } }
                    }, k === 'back' ? icon('fa-backspace') : (k === 'clear' ? t('clear') : k));
                    keyBtns.push(b);
                    keys.appendChild(b);
                });
                parts.push(h('section', { class: 'trp-card trp-pin' }, [
                    h('div', { class: 'trp-pin__head' }, [h('strong', { text: t('enter_pin') }),
                        h('span', { class: 'trp-muted', text: view.course && view.course.kind === 'document' ? t('k_pin_doc_hint') : t('k_pin_hint') })]),
                    dots, keys
                ]));
            }
            ctx.main.appendChild(h('div', { class: 'trp-ack' }, parts));
            var actions = ctx.foot.querySelector('.trp-foot__actions');
            ctx.foot.querySelector('.trp-foot__actions .trp-btn--primary').hidden = true;
            actions.appendChild(signBtn);
            ctx.onGate = function (met) { serverOk = !!met; syncBtn(); };
            function press(k) {
                if (busy) { return; }
                if (k === 'clear') { pin = ''; } else if (k === 'back') { pin = pin.slice(0, -1); } else if (pin.length < 12) { pin += k; }
                sync();
            }
            function localOk() { return checked && inked && (!needPin || pin.length >= 4); }
            function syncBtn() { signBtn.disabled = busy || !localOk() || !serverOk; }
            function todo() {
                var key = 'k_ack_' + (checked ? '' : 't') + (inked ? '' : 's') + (!needPin || pin.length >= 4 ? '' : 'p');
                return key === 'k_ack_' ? '' : t(key);
            }
            function sync() {
                check.setAttribute('aria-checked', checked ? 'true' : 'false');
                check.classList.toggle('is-on', checked);
                if (dots) {
                    clear(dots);
                    for (var i = 0; i < Math.max(4, pin.length); i++) { dots.appendChild(h('span', { class: 'trp-pin__dot' + (i < pin.length ? ' is-on' : '') })); }
                }
                ctx.gate.set(localOk(), localOk() ? t('k_checking') : todo(), null, true);
                syncBtn();
            }
            check.addEventListener('click', function () { if (!busy) { checked = !checked; sync(); } });
            signBtn.addEventListener('click', function () {
                if (signBtn.disabled || busy) { return; }
                var png = null;
                if (a.require_signature && pad && typeof pad.toPng === 'function') { png = pad.toPng(); }
                var p = pin;
                pin = '';   // never kept after the call
                busy = true;
                ctx.gate.error(null);
                setBusy(signBtn, true);
                keyBtns.forEach(function (b) { b.disabled = true; });
                sync();
                Promise.resolve().then(function () { return adapter.signAck(l.uid, { signature_png: png, pin: needPin ? p : null }); }).then(function (res) {
                    p = '';
                    if (!ctx.live()) { return; }
                    progress.done[l.uid] = true;
                    if (res && res.done) { mergeDone(res.done); }
                    saveProgress();
                    var st = res && res.run_status;
                    if ((res && res.receipt) || st === 'completed' || st === 'awaiting_signature' || st === 'awaiting_session' || st === 'awaiting_evaluation') {
                        finishCourse(res);
                        return;
                    }
                    ctx.goNext();
                }, function (e) {
                    p = '';
                    if (!ctx.live()) { return; }
                    busy = false;
                    setBusy(signBtn, false);
                    keyBtns.forEach(function (b) { b.disabled = false; });
                    if (e && (e.code === 'signature_invalid' || e.code === 'signature_empty') && pad && typeof pad.clear === 'function') { pad.clear(); inked = false; }
                    if (e && e.data && typeof e.data === 'object' && 'can_complete' in e.data) { ctx.gate.server(e.data); }
                    sync();
                    ctx.gate.error(errText(e));   // in the footer, next to the button
                });
            });
            sync();
        }

        function signaturePad(onChange) {
            var canvas = h('canvas', { class: 'trp-sign__canvas', 'aria-label': t('sign_here'), role: 'img' });
            var clearBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--sm' }, [icon('fa-eraser'), t('clear')]);
            var el = h('section', { class: 'trp-card trp-sign' }, [
                h('div', { class: 'trp-sign__head' }, [h('strong', { text: t('sign_here') }), clearBtn]),
                h('div', { class: 'trp-sign__area' }, [canvas, h('span', { class: 'trp-sign__line', 'aria-hidden': 'true' })])
            ]);
            var ctx2 = null;
            var drawing = false;
            var last = null;
            var ink = 0;
            function size() {
                var r = canvas.getBoundingClientRect();
                var dpr = window.devicePixelRatio || 1;
                if (!r.width) { return; }
                canvas.width = Math.round(r.width * dpr);
                canvas.height = Math.round(r.height * dpr);
                ctx2 = canvas.getContext('2d');
                ctx2.setTransform(dpr, 0, 0, dpr, 0, 0);
                ctx2.lineWidth = 2.6;
                ctx2.lineCap = 'round';
                ctx2.lineJoin = 'round';
                ctx2.strokeStyle = getComputedStyle(canvas).color || '#16232a';
                ink = 0;
                onChange(false);
            }
            function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
            canvas.addEventListener('pointerdown', function (e) {
                if (!ctx2) { size(); }
                drawing = true;
                last = pos(e);
                try { canvas.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
                e.preventDefault();
            });
            canvas.addEventListener('pointermove', function (e) {
                if (!drawing || !ctx2) { return; }
                var p = pos(e);
                ctx2.beginPath();
                ctx2.moveTo(last.x, last.y);
                ctx2.lineTo(p.x, p.y);
                ctx2.stroke();
                ink += Math.hypot(p.x - last.x, p.y - last.y);
                last = p;
                if (ink >= 60) { onChange(true); }
            });
            function end() { drawing = false; last = null; }
            canvas.addEventListener('pointerup', end);
            canvas.addEventListener('pointercancel', end);
            clearBtn.addEventListener('click', function () { if (ctx2) { ctx2.clearRect(0, 0, canvas.width, canvas.height); } ink = 0; onChange(false); });
            setTimeout(size, 0);
            var onResize = function () { size(); };
            window.addEventListener('resize', onResize);
            cleanup.push(function () { window.removeEventListener('resize', onResize); });
            return { el: el };
        }

        // ---------------- quiz ----------------
        function quizMeta(qz, noAttempts) {
            var out = [h('span', null, [icon('fa-list-ol'), qz.question_count === 1 ? t('question_1') : t('questions_n', { n: qz.question_count })]),
                h('span', null, [icon('fa-bullseye'), t('pass_pct', { pct: qz.pass_pct })])];
            if (qz.time_limit_s) { out.push(h('span', null, [icon('fa-stopwatch'), t('time_limit_min', { n: Math.round(qz.time_limit_s / 60) })])); }
            if (!noAttempts) { out.push(h('span', null, [icon('fa-redo'), qz.max_attempts === 0 ? t('unlimited') : (qz.max_attempts === 1 ? t('attempts_1') : t('attempts_n', { n: qz.max_attempts }))])); }
            return out;
        }

        function renderQuizIntro(ctx) {
            var l = ctx.lesson;
            var qz = l.quiz;
            ctx.foot.querySelector('.trp-foot__actions .trp-btn--primary').hidden = true;
            if (!qz) { ctx.main.appendChild(h('div', { class: 'trp-card trp-empty-note', text: t('questions_n', { n: 0 }) })); return; }
            var err = h('div', { class: 'trp-vstatus', role: 'status', 'aria-live': 'polite', hidden: true });
            var isExam = qz.role === 'exam';
            var info = isKiosk && fn('quizInfo') ? adapter.quizInfo(l.uid) : null;
            // The kiosk says "N tries left" under the facts, so the facts leave out "N attempts".
            var triesNote = !!(info && typeof info === 'object' && !info.passed && !info.locked && Number(info.max) > 0 && typeof info.left === 'number');
            var start = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--xl' }, [isKiosk && isExam ? t('k_exam_start') : t('quiz_start'), icon('fa-arrow-right')]);
            var kicker = isExam ? t('t_exam') : (qz.role === 'check' ? t('t_check') : t('t_quiz'));
            var sameAsTitle = String(l.title || '').trim().toLowerCase() === String(kicker).trim().toLowerCase();
            var card = h('section', { class: 'trp-card trp-qintro' }, [
                h('span', { class: 'trp-qintro__icon', 'aria-hidden': 'true' }, icon(isExam ? 'fa-graduation-cap' : 'fa-question-circle')),
                sameAsTitle ? null : h('div', { class: 'trp-kicker', text: kicker }),
                h('h2', { class: 'trp-qintro__title', text: l.title || t('t_quiz') }),
                qz.intro ? h('p', { class: 'trp-qintro__text', text: qz.intro }) : null,
                l.description_html ? setTrustedHtml(h('div', { class: 'trp-article trp-article--desc' }), l.description_html) : null,
                h('div', { class: 'trp-meta trp-meta--center' }, quizMeta(qz, triesNote)),
                !canGrade ? h('p', { class: 'trp-note trp-note--center' }, [icon('fa-info-circle'), h('span', { text: t('grading_authors_only') })]) : null,
                start, err
            ]);
            ctx.main.appendChild(card);
            // '\u0000' = the text is the whole footer line (the exam's own wording, with the lock icon)
            if (isKiosk && isExam) { ctx.gate.set(!!progress.done[l.uid], '\u0000' + t('k_exam_after'), null); } else { ctx.gate.set(!!progress.done[l.uid], t('req_quiz'), null); }
            if (info && typeof info === 'object') {
                var note = null;
                if (info.passed) {
                    note = h('p', { class: 'trp-note trp-note--center' }, [icon('fa-check-circle'), h('span', { text: t('k_passed_already') })]);
                    start.disabled = true;
                } else if (info.locked) {
                    note = h('p', { class: 'trp-note trp-note--center trp-note--warn' }, [icon('fa-lock'), h('span', { text: t('locked_trainer') })]);
                    start.disabled = true;
                } else if (Number(info.max) > 0 && typeof info.left === 'number') {
                    var lft = info.left + (info.open ? 1 : 0);   // a started try is resumed, not used up
                    note = h('p', { class: 'trp-note trp-note--center' }, [icon('fa-redo'), h('span', { text: lft === 1 ? t('attempts_left_1') : t('attempts_left_n', { n: lft }) })]);
                }
                if (note) { card.insertBefore(note, start); }
            }
            start.addEventListener('click', function () {
                start.disabled = true;
                err.hidden = false;
                err.className = 'trp-vstatus';
                clear(err);
                err.appendChild(icon('fa-circle-notch', 'fa-spin'));
                err.appendChild(h('span', { text: t('starting') }));
                Promise.resolve(typeof adapter.startQuiz === 'function' ? adapter.startQuiz(l.uid, lang) : Promise.reject(new Error('No quiz runner.'))).then(function (data) {
                    runQuiz(l, data);
                }, function (e) {
                    start.disabled = false;
                    err.className = 'trp-vstatus is-error';
                    clear(err);
                    err.appendChild(icon('fa-exclamation-triangle'));
                    err.appendChild(h('span', { text: (e && e.message) || t('video_error') }));
                });
            });
        }

        /**
         * A content lesson's quick check (lesson frame, check mode): what it is, the lesson's settings
         * (questions, pass mark, tries), must-pass or optional, tries left / passed / locked, then Start ->
         * the quiz runner. Optional checks (and preview) can be skipped; "Back to the lesson" reopens it.
         */
        function renderCheckIntro(ctx) {
            var l = ctx.lesson;
            var qz = checkOf(l);
            var mustPass = !!qz.must_pass;
            var info = checkInfo(l.uid);
            var actions = ctx.foot.querySelector('.trp-foot__actions');
            Array.prototype.forEach.call(actions.children, function (b) { b.hidden = true; });
            ctx.noNext = true;
            var err = h('div', { class: 'trp-vstatus', role: 'status', 'aria-live': 'polite', hidden: true });
            var start = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--xl' }, [info && info.open ? t('qc_resume') : t('qc_start'), icon('fa-arrow-right')]);
            var back = h('button', { type: 'button', class: 'trp-btn trp-btn--text trp-qintro__back', on: { click: function () { openLesson(l.uid); } } },
                [icon('fa-undo'), t('qc_back_lesson')]);
            var triesNote = !!(info && !info.passed && !info.locked && Number(info.max) > 0 && typeof info.left === 'number');
            var card = h('section', { class: 'trp-card trp-qintro trp-qintro--check' }, [
                h('span', { class: 'trp-qintro__icon', 'aria-hidden': 'true' }, icon('fa-clipboard-check')),
                h('div', { class: 'trp-kicker', text: t('t_check') }),
                h('h2', { class: 'trp-qintro__title', text: l.title || t('t_check') }),
                h('p', { class: 'trp-qintro__text', text: qz.intro || t('qc_intro') }),
                h('div', { class: 'trp-meta trp-meta--center' }, quizMeta(qz, triesNote)),
                h('p', { class: 'trp-note trp-note--center' + (mustPass ? ' trp-note--must' : '') },
                    [icon(mustPass ? 'fa-flag-checkered' : 'fa-info-circle'), h('span', { text: mustPass ? t('qc_must') : t('qc_optional') })]),
                !canGrade ? h('p', { class: 'trp-note trp-note--center' }, [icon('fa-info-circle'), h('span', { text: t('grading_authors_only') })]) : null,
                start, err, back
            ]);
            ctx.main.appendChild(card);
            var closed = false;   // passed, locked or out of tries: nothing to start
            if (info) {
                var notes = [];
                // Tries left counts a try that was started and not sent yet (it is resumed, not used up).
                var left = typeof info.left === 'number' && Number(info.max) > 0 ? info.left + (info.open ? 1 : 0) : null;
                if (info.passed) {
                    notes.push(h('p', { class: 'trp-note trp-note--center' }, [icon('fa-check-circle'), h('span', { text: t('qc_passed') })]));
                    closed = true;
                } else if (info.locked) {
                    notes.push(h('p', { class: 'trp-note trp-note--center trp-note--warn' }, [icon('fa-lock'), h('span', { text: t('locked_trainer') })]));
                    closed = true;
                } else {
                    if (info.open) { notes.push(h('p', { class: 'trp-note trp-note--center' }, [icon('fa-history'), h('span', { text: t('qc_open') })])); }
                    if (left !== null && left <= 0) {
                        notes.push(h('p', { class: 'trp-note trp-note--center trp-note--warn' }, [icon('fa-redo'), h('span', { text: t('qc_no_tries') })]));
                        closed = true;
                    } else if (left !== null && mustPass && isKiosk && !progress.done[l.uid]) {
                        // Must pass with N tries: say what running out means, and warn on the last try.
                        notes.push(left === 1 ? h('p', { class: 'trp-note trp-note--center trp-note--warn trp-qc__last' }, [icon('fa-exclamation-triangle'), h('span', { text: t('qc_must_last') })])
                            : h('p', { class: 'trp-note trp-note--center trp-qc__tries' }, [icon('fa-redo'), h('span', { text: t('qc_must_tries', { n: left }) })]));
                    } else if (left !== null) {
                        notes.push(h('p', { class: 'trp-note trp-note--center' }, [icon('fa-redo'), h('span', { text: left === 1 ? t('attempts_left_1') : t('attempts_left_n', { n: left }) })]));
                    }
                }
                notes.forEach(function (n) { card.insertBefore(n, start); });
            }
            start.disabled = closed;
            start.hidden = closed;
            // The footer says whether the check holds the lesson back; optional checks (and preview) can be skipped.
            var needPass = mustPass && !progress.done[l.uid];
            ctx.footLabel = function () { return needPass ? t('qc_foot_must') : t('qc_foot_optional'); };
            ctx.gate.set(!needPass, '', null);
            // Kiosk, must pass: the footer would only repeat the card's note and has no action (no skip), so it
            // goes, and Start and "Back to the lesson" stay in view.
            if (needPass && isKiosk) { ctx.foot.hidden = true; }
            if (!needPass || !isKiosk) {
                var goOn = h('button', { type: 'button', class: 'trp-btn ' + (closed ? 'trp-btn--primary' : 'trp-btn--ghost') + ' trp-qc__skip' },
                    [closed ? t('continue') : t('qc_skip'), icon('fa-arrow-right')]);
                goOn.addEventListener('click', function () { ctx.goNext(); });
                actions.appendChild(goOn);
            }
            start.addEventListener('click', function () {
                if (start.disabled) { return; }
                start.disabled = true;
                err.hidden = false;
                err.className = 'trp-vstatus';
                clear(err);
                err.appendChild(icon('fa-circle-notch', 'fa-spin'));
                err.appendChild(h('span', { text: t('starting') }));
                Promise.resolve(typeof adapter.startQuiz === 'function' ? adapter.startQuiz(l.uid, lang) : Promise.reject(new Error('No quiz runner.'))).then(function (data) {
                    if (!ctx.live()) { return; }
                    runQuiz(l, data);
                }, function (e) {
                    if (!ctx.live()) { return; }
                    start.disabled = !!(e && (e.code === 'attempts_exhausted' || e.code === 'already_passed'));
                    err.className = 'trp-vstatus is-error';
                    clear(err);
                    err.appendChild(icon('fa-exclamation-triangle'));
                    err.appendChild(h('span', { text: (e && e.message) || t('video_error') }));
                });
            });
        }

        function runQuiz(l, data) {
            runCleanup();
            clear(screen);
            screen.scrollTop = 0;
            var qs = data.questions || [];
            var quiz = data.quiz || {};
            var graded = !!data.graded && canGrade;
            var answers = {};
            var flags = {};
            var cur = 0;
            var startedAt = Date.now();
            var remaining = typeof quiz.deadline_remaining_s === 'number' ? quiz.deadline_remaining_s : null;
            var deadline = remaining !== null ? Date.now() + remaining * 1000 : null;
            var submitted = false;
            // Kiosk (§7.6 #8): answers are saved as they change (debounced 400 ms per question) and every
            // pending save is flushed and awaited before the submit; a resumed attempt pre-fills them.
            var pendingSave = {};
            var inflight = [];
            if (isKiosk && data.saved && typeof data.saved === 'object') {
                Object.keys(data.saved).forEach(function (u) { if (Array.isArray(data.saved[u])) { answers[u] = data.saved[u].map(String); } });
            }
            function sendSave(qUid) {
                var p = Promise.resolve().then(function () { return adapter.onAnswer(data.attempt_token, qUid, (answers[qUid] || []).slice()); }).then(null, function (e) {
                    if (e && e.code !== 'time_up' && footNote) { footNote.textContent = t('k_save_failed'); }
                });
                inflight.push(p);
                p.then(function () { var i = inflight.indexOf(p); if (i !== -1) { inflight.splice(i, 1); } });
                return p;
            }
            function queueSave(qUid) {
                if (!isKiosk || !fn('onAnswer')) { return; }
                clearTimeout(pendingSave[qUid]);
                pendingSave[qUid] = setTimeout(function () { delete pendingSave[qUid]; sendSave(qUid); }, 400);
            }
            function flushSaves() {
                Object.keys(pendingSave).forEach(function (u) { clearTimeout(pendingSave[u]); delete pendingSave[u]; sendSave(u); });
                return Promise.all(inflight.slice());
            }
            cleanup.push(function () { if (!submitted) { flushSaves(); } });
            var timerPill = h('div', { class: 'trp-timer', hidden: deadline === null, role: 'timer', 'aria-live': 'off' });
            var exam = !!(l.quiz && l.quiz.role === 'exam');
            var isCheck = !!checkOf(l);
            var header = h('header', { class: 'trp-qhead' }, [
                h('span', { class: 'trp-qhead__icon', 'aria-hidden': 'true' }, icon(exam ? 'fa-lock' : (isCheck ? 'fa-clipboard-check' : 'fa-question-circle'))),
                // A quick check's title is its lesson's; the course name would only cut it off.
                h('h1', { class: 'trp-qhead__title' }, [h('span', { text: isCheck ? t('qc_title', { title: l.title || quiz.title || '' }) : (quiz.title || l.title || '') }),
                    view.course.name && !isCheck ? h('span', { class: 'trp-qhead__course', text: ' · ' + view.course.name }) : null]),
                isKiosk && data.attempt_number ? h('span', { class: 'trp-pill trp-pill--info' }, [icon('fa-redo'),
                    Number(data.attempts_max) > 0 ? t('k_attempt_of', { n: data.attempt_number, max: data.attempts_max }) : t('attempt_n', { n: data.attempt_number })]) : null,
                h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--sm', on: { click: function () { openLesson(l.uid, isCheck ? { check: true } : null); } } },
                    [icon('fa-times'), isKiosk && exam ? t('k_exam_leave') : (isCheck ? t('qc_leave') : t('leave_quiz'))]),
                timerPill
            ]);
            var progLabel = h('strong', { class: 'trp-qprog__label' });
            var segs = h('div', { class: 'trp-qprog__segs' + (qs.length > 20 ? ' is-continuous' : ''), 'aria-hidden': 'true' });
            var answeredLabel = h('span', { class: 'trp-muted' });
            var prog = h('div', { class: 'trp-qprog' }, [progLabel, segs, answeredLabel]);
            var cardHost = h('div', { class: 'trp-qhost' });
            var prevBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--lg' }, [icon('fa-chevron-left'), t('previous')]);
            var flagBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--text', 'aria-pressed': 'false' }, [icon('fa-flag'), t('flag')]);
            // "Flag for review" is exam chrome: a short quick check goes without it.
            var noFlag = isCheck && qs.length <= 5;
            flagBtn.hidden = noFlag;
            var nextBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--lg' });
            var footNote = isKiosk ? h('span', { class: 'trp-muted trp-qfoot__note' }, [icon('fa-cloud-upload-alt'), t('k_saving_note')])
                : h('span', { class: 'trp-muted trp-qfoot__note' }, [icon('fa-eye'), t('nothing_recorded')]);
            var foot = h('footer', { class: 'trp-qfoot' }, [prevBtn, flagBtn, h('span', { class: 'trp-qfoot__spacer' }), footNote, nextBtn]);
            var page = h('div', { class: 'trp-quiz' }, [header, prog, cardHost, foot]);
            screen.appendChild(page);
            var card = null;

            function answeredCount() { return qs.filter(function (q) { return (answers[q.uid] || []).length > 0; }).length; }
            function renderProgress() {
                progLabel.textContent = t('question_of', { n: cur + 1, total: qs.length });
                answeredLabel.textContent = t('answered_n', { n: answeredCount(), total: qs.length });
                clear(segs);
                if (qs.length > 20) {
                    segs.appendChild(h('span', { class: 'trp-qprog__fill', style: { width: Math.round((cur + 1) * 100 / qs.length) + '%' } }));
                } else {
                    qs.forEach(function (q, i) {
                        segs.appendChild(h('span', { class: 'trp-qprog__seg' + ((answers[q.uid] || []).length ? ' is-answered' : '') + (i === cur ? ' is-current' : '') }));
                    });
                }
            }
            function isLast() { return cur === qs.length - 1; }
            function renderQuestion() {
                var q = qs[cur];
                clear(cardHost);
                card = questionCard(q, { selected: (answers[q.uid] || []).slice() }, {
                    t: t, index: cur, total: qs.length,
                    onChange: function (sel) { answers[q.uid] = sel; queueSave(q.uid); renderProgress(); syncNext(); }
                });
                cardHost.appendChild(card.el);
                prevBtn.disabled = cur === 0;
                flagBtn.setAttribute('aria-pressed', flags[q.uid] ? 'true' : 'false');
                flagBtn.classList.toggle('is-on', !!flags[q.uid]);
                clear(flagBtn);
                flagBtn.appendChild(icon('fa-flag'));
                flagBtn.appendChild(document.createTextNode(flags[q.uid] ? t('flagged') : t('flag')));
                renderProgress();
                syncNext();
                card.focusFirst();
            }
            function syncNext() {
                var q = qs[cur];
                var answered = (answers[q.uid] || []).length > 0;
                clear(nextBtn);
                var last = isLast();
                var label = !last ? t('next_question') : (quiz.show_review !== false ? t('review_answers') : (graded ? t('submit') : t('review_answers')));
                nextBtn.appendChild(document.createTextNode(label));
                nextBtn.appendChild(icon(last && graded && quiz.show_review === false ? 'fa-paper-plane' : 'fa-chevron-right'));
                nextBtn.className = 'trp-btn trp-btn--lg ' + (answered ? 'trp-btn--primary' : 'trp-btn--soft');
            }
            var mode = 'question';
            function goTo(i) {
                if (i < 0 || i >= qs.length) { return; }
                cur = i;
                mode = 'question';
                flagBtn.hidden = noFlag;
                nextBtn.hidden = false;
                renderQuestion();
            }
            prevBtn.addEventListener('click', function () {
                if (mode === 'review') { goTo(qs.length - 1); return; }
                goTo(cur - 1);
            });
            flagBtn.addEventListener('click', function () { var q = qs[cur]; flags[q.uid] = !flags[q.uid]; renderQuestion(); });
            nextBtn.addEventListener('click', function () {
                if (!isLast()) { goTo(cur + 1); return; }
                if (quiz.show_review === false && graded) { submit(false); return; }
                renderReview();
            });
            onKeys(function (e) {
                if (!page.isConnected || mode !== 'question' || !card) { return; }
                if (/^[1-8]$/.test(e.key)) { card.choose(Number(e.key) - 1); e.preventDefault(); return; }
                var onOpt = e.target && e.target.classList && e.target.classList.contains('trp-opt');
                if (e.key === 'Enter' && (onOpt || e.target === document.body)) { e.preventDefault(); nextBtn.click(); }
            });

            function renderReview(timeUp) {
                mode = 'review';
                clear(cardHost);
                card = null;
                var unanswered = qs.filter(function (q) { return !(answers[q.uid] || []).length; }).length;
                var grid = h('ol', { class: 'trp-review' });
                qs.forEach(function (q, i) {
                    var ans = (answers[q.uid] || []).length > 0;
                    grid.appendChild(h('li', null, h('button', {
                        type: 'button', class: 'trp-review__item' + (ans ? ' is-answered' : '') + (flags[q.uid] ? ' is-flagged' : ''), disabled: submitted || timeUp,
                        on: { click: function () { goTo(i); } }
                    }, [
                        h('span', { class: 'trp-review__n', text: String(i + 1) }),
                        h('span', { class: 'trp-review__text', text: q.text }),
                        h('span', { class: 'trp-review__state' }, [flags[q.uid] ? icon('fa-flag') : null, ans ? t('change') : t('unanswered')])
                    ])));
                });
                var submitBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--xl' }, [icon('fa-paper-plane'), t('submit')]);
                submitBtn.addEventListener('click', function () { submit(false); });
                cardHost.appendChild(h('section', { class: 'trp-card trp-reviewcard' }, [
                    h('h2', { class: 'trp-card__title', text: t('review') }),
                    h('p', { class: 'trp-muted', text: timeUp ? t('time_up_ungraded') : t('review_hint') }),
                    h('p', { class: unanswered ? 'trp-note trp-note--warn' : 'trp-note' }, [icon(unanswered ? 'fa-exclamation-circle' : 'fa-check-circle'), h('span', { text: unanswered ? t('n_unanswered', { n: unanswered }) : t('all_answered') })]),
                    grid,
                    graded ? h('div', { class: 'trp-reviewcard__actions' }, submitBtn)
                        : h('div', { class: 'trp-note trp-note--center trp-note--lock' }, [icon('fa-lock'), h('span', { text: t('grading_authors_only') })])
                ]));
                progLabel.textContent = t('review');
                answeredLabel.textContent = t('answered_n', { n: answeredCount(), total: qs.length });
                prevBtn.disabled = !!(submitted || timeUp);
                flagBtn.hidden = true;
                nextBtn.hidden = true;
                if (!graded) { footNote.textContent = t('quiz_not_graded'); }
                var hd = cardHost.querySelector('.trp-card__title');
                if (hd) { hd.tabIndex = -1; hd.focus({ preventScroll: true }); }
            }

            function submit(timeUp) {
                if (submitted || !graded) { if (timeUp && !graded) { renderReview(true); } return; }
                submitted = true;
                stopTimer();
                clear(cardHost);
                cardHost.appendChild(h('div', { class: 'trp-card trp-busy', role: 'status' }, [icon('fa-circle-notch', 'fa-spin'), h('span', { text: t('submitting') })]));
                foot.hidden = true;
                var body = {};
                qs.forEach(function (q) { if ((answers[q.uid] || []).length) { body[q.uid] = answers[q.uid].slice(); } });
                (isKiosk ? flushSaves() : Promise.resolve()).then(function () { return adapter.submitQuiz(data.attempt_token, body); }).then(function (res) {
                    renderResult(l, data, answers, res, Date.now() - startedAt, timeUp);
                }, function (e) {
                    submitted = false;
                    foot.hidden = false;
                    renderReview();
                    cardHost.insertBefore(h('div', { class: 'trp-vstatus is-error', role: 'alert' }, [icon('fa-exclamation-triangle'), h('span', { text: (e && e.message) || t('video_error') })]), cardHost.firstChild);
                });
            }

            var timer = null;
            function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }
            function tick() {
                if (deadline === null) { return; }
                var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
                clear(timerPill);
                timerPill.appendChild(icon('fa-stopwatch'));
                timerPill.appendChild(h('span', { class: 'trp-mono', text: fmt(left) }));
                timerPill.appendChild(h('span', { class: 'visually-hidden', text: t('time_left') }));
                timerPill.classList.toggle('is-low', left <= 60);
                if (left <= 0) {
                    stopTimer();
                    if (graded) { submit(true); } else { renderReview(true); }
                }
            }
            if (deadline !== null) { tick(); timer = setInterval(tick, 1000); cleanup.push(stopTimer); }
            if (!qs.length) { cardHost.appendChild(h('div', { class: 'trp-card trp-empty-note', text: t('questions_n', { n: 0 }) })); return; }
            renderQuestion();
        }

        function renderResult(l, data, answers, res, tookMs, timeUp) {
            runCleanup();
            clear(screen);
            screen.scrollTop = 0;
            var passed = !!res.passed;
            var score = Math.round(Number(res.score_pct) || 0);
            var fb = res.feedback || { mode: 'score_only', missed: [] };
            var total = (data.questions || []).length;
            var missed = fb.missed || [];
            var optText = {};
            var qText = {};
            (data.questions || []).forEach(function (q) { qText[q.uid] = q.text; (q.options || []).forEach(function (o, i) { optText[o.uid] = { text: o.text, letter: LETTERS[i] }; }); });
            var correctN = fb.mode === 'score_only' ? null : total - missed.length;
            var isCheck = !!checkOf(l);
            var mustPass = !(l.quiz && l.quiz.must_pass === false);
            progress.tries[l.uid] = (Number(progress.tries[l.uid]) || 0) + 1;
            if (passed || !mustPass) { progress.done[l.uid] = true; }
            saveProgress();
            var maxTries = l.quiz && typeof l.quiz.max_attempts === 'number' ? l.quiz.max_attempts : 0;
            var triesUsed = progress.tries[l.uid];
            var validity = Number(view.course && view.course.validity_months) || 0;
            var kx = isKiosk ? (fn('onQuizResult') ? (adapter.onQuizResult(l.uid, res) || null) : null) : null;
            var kLocked = isKiosk && (res.locked || res.next === 'locked');
            // Kiosk: the server counts attempts (extra tries from a trainer included), not this browser.
            var triesLeft = maxTries ? Math.max(0, maxTries - triesUsed) : null;
            if (isKiosk && typeof res.attempt_number === 'number') {
                triesUsed = res.attempt_number;
                maxTries = Number(res.attempts_max) || 0;
                triesLeft = typeof res.attempts_left === 'number' ? res.attempts_left : (maxTries ? Math.max(0, maxTries - triesUsed) : null);
            }

            var ringEl = h('div', { class: 'trp-result__ring' }, [
                ring(score, passed ? 'trp-ring--ok' : 'trp-ring--bad', score + '%'),
                h('div', { class: 'trp-result__score' }, [h('span', { class: 'trp-result__num', text: String(score) }), h('span', { class: 'trp-result__pct', text: '%' }), h('span', { class: 'trp-result__cap', text: t('your_score') })])
            ]);
            if (passed && !reducedMotion()) { ringEl.appendChild(confetti()); }
            // The pass mark is in the line under the headline; the third stat says what comes next.
            // A quick check certifies nothing, so a pass shows the attempt, never "Good for N months".
            var third = passed
                ? (validity && !isCheck ? stat(t('good_for'), validity === 1 ? t('month_1') : t('months_n', { n: validity })) : stat(t('attempt_label'), maxTries ? t('attempt_of', { n: triesUsed, m: maxTries }) : String(triesUsed)))
                : stat(t('tries_left'), triesLeft !== null ? String(triesLeft) : t('unlimited_short'));
            // A quick check's result is compact (smaller ring, no stats row): what was missed and why stays in view.
            var stats = isCheck ? null : h('div', { class: 'trp-stats' }, [
                correctN !== null ? stat(t('correct_label'), t('of', { n: correctN, m: total })) : stat(t('correct_label'), t('points_of', { n: res.points_earned, m: res.points_possible })),
                stat(t('time_label'), fmtTook(tookMs)),
                third
            ]);
            var headline = passed ? t('you_passed') : t('not_passed');
            // A missed must-know question is said once, in the red banner below.
            var msg = passed ? t('pass_msg') : t('fail_msg');
            if (isKiosk && passed) { msg = kx && kx.signLabel ? t('k_pass_sign_msg', { first: adapter.learnerFirst || '', course: view.course.name || '' }) : t('k_pass_msg', { first: adapter.learnerFirst || '' }); }
            if (isCheck && !passed) { msg = mustPass ? t('qc_fail_must_msg') : t('qc_fail_msg'); }
            if (kLocked && !passed) { msg = t('k_locked_msg'); }
            else if (isKiosk && !passed && typeof res.attempts_left === 'number' && Number(res.attempts_max) > 0
                && !(isCheck && mustPass && res.attempts_left === 1)) {   // a must-pass check's last try: the warning below says it
                msg = msg + ' ' + (res.attempts_left === 1 ? t('attempts_left_1') : t('attempts_left_n', { n: res.attempts_left }));
            }
            var line = t('pass_mark', { pct: res.pass_pct }) + ' · ' + (isCheck && correctN !== null ? t('qc_correct_n', { n: correctN, m: total })
                : t('points_of', { n: res.points_earned, m: res.points_possible }));
            var hero = h('section', { class: 'trp-card trp-result' + (passed ? ' is-pass' : ' is-fail') + (isCheck ? ' trp-result--compact' : '') }, [
                ringEl,
                h('div', { class: 'trp-result__body' }, [
                    h('span', { class: 'trp-pill ' + (passed ? 'trp-pill--ok' : 'trp-pill--bad') }, [icon(passed ? 'fa-check-circle' : 'fa-times-circle'), passed ? t('passed_chip') : t('failed_chip')]),
                    h('h1', { class: 'trp-result__title', text: headline }),
                    h('p', { class: 'trp-result__line' }, [line]),
                    h('p', { class: 'trp-result__msg', text: timeUp ? t('time_up') + ' ' + msg : msg }),
                    stats
                ])
            ]);
            var parts = [h('div', { class: 'trp-result__meta' }, [
                h('span', null, [icon(l.quiz && l.quiz.role === 'exam' ? 'fa-lock' : (isCheck ? 'fa-clipboard-check' : 'fa-question-circle')),
                    (isCheck ? t('t_check') + ' · ' : '') + (l.title || '') + (view.course.name && !isCheck ? ' · ' + view.course.name : '')]),
                isKiosk && res.attempt_number ? h('span', { class: 'trp-pill trp-pill--info' }, [icon('fa-redo'),
                    Number(res.attempts_max) > 0 ? t('k_attempt_of', { n: res.attempt_number, max: res.attempts_max }) : t('attempt_n', { n: res.attempt_number })]) : null,
                h('span', { class: 'trp-muted', text: t('finished_at', { time: new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }) + ' · ' + t('took', { t: fmtTook(tookMs) }) })
            ]), hero];
            if (res.critical_missed > 0) {
                parts.push(h('div', { class: 'trp-banner trp-banner--bad', role: 'alert' }, [icon('fa-exclamation-triangle'), h('span', { text: t('critical_missed') })]));
            }
            // Must pass, one try left: say what running out means before the last try.
            if (isKiosk && isCheck && mustPass && !passed && !kLocked && triesLeft === 1) {
                parts.push(h('div', { class: 'trp-banner trp-banner--warn', role: 'status' }, [icon('fa-exclamation-triangle'), h('span', { text: t('qc_fail_last') })]));
            }
            // A missed quick check: back to the lesson's content ("Watch again" / "Read again").
            var againBtn = null;
            if (isCheck && !passed && !kLocked) {
                var againKey = l.type === 'video' ? 'qc_again_video' : (l.type === 'image' ? 'qc_again_image' : 'qc_again_read');
                againBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--sm trp-qc__again', on: { click: function () { openLesson(l.uid); } } },
                    [icon(l.type === 'video' ? 'fa-play-circle' : 'fa-undo'), t(againKey)]);
            }
            var reviewCard = h('section', { class: 'trp-card trp-missed' }, [
                h('header', { class: 'trp-missed__head' }, [
                    h('span', { class: 'trp-tile trp-tile--muted', 'aria-hidden': 'true' }, icon('fa-book-open')),
                    h('h2', { class: 'trp-card__title', text: t('what_to_review') }),
                    missed.length ? h('span', { class: 'trp-pill trp-pill--bad' }, [icon('fa-times'), t('n_missed', { n: missed.length })]) : null,
                    againBtn
                ])
            ]);
            if (fb.mode === 'score_only') {
                reviewCard.appendChild(h('p', { class: 'trp-muted', text: t('score_only_note') }));
            } else if (!missed.length) {
                reviewCard.appendChild(h('p', { class: 'trp-muted', text: t('nothing_missed') }));
            } else {
                // A quick check goes straight to the missed questions (each names its topic).
                if (!isCheck) { reviewCard.appendChild(h('p', { class: 'trp-muted', text: t('quick_look') })); }
                if (!isCheck && (res.topics_missed || []).length) {
                    reviewCard.appendChild(h('div', { class: 'trp-topics' }, [h('strong', { text: t('topics_missed') })].concat(res.topics_missed.map(function (tp) { return h('span', { class: 'trp-chip', text: tp }); }))));
                }
                reviewCard.appendChild(h('ol', { class: 'trp-missed__list' }, missed.map(function (m) {
                    var items = [h('div', { class: 'trp-missed__q', text: m.text || qText[m.uid] || '' })];
                    if (m.topic) { items.push(h('div', { class: 'trp-muted trp-missed__topic', text: m.topic })); }
                    if (m.explanation) { items.push(h('div', { class: 'trp-missed__why' }, [h('strong', { text: t('why') }), h('span', { text: m.explanation })])); }
                    (m.chosen_feedback || []).forEach(function (f) { items.push(h('div', { class: 'trp-missed__fb' }, [h('strong', { text: t('your_choice') }), h('span', { text: f })])); });
                    if (fb.answers && fb.answers[m.uid]) {
                        var labels = fb.answers[m.uid].map(function (u) { return optText[u] ? optText[u].letter + ') ' + optText[u].text : ''; }).filter(Boolean);
                        if (labels.length) { items.push(h('div', { class: 'trp-missed__ans' }, [h('strong', { text: t('correct_answer') }), h('span', { text: labels.join('; ') })])); }
                    }
                    return h('li', null, items);
                })));
            }
            var ach;
            var awards = Array.isArray(res.achievements) ? res.achievements.filter(function (a) { return a && typeof a === 'object'; }) : [];
            if (awards.length) {
                // §7.6 #9: text via textContent; icon and colour checked against strict patterns.
                ach = h('section', { class: 'trp-card trp-ach trp-ach--won' }, [
                    h('div', { class: 'trp-kicker', text: awards.length === 1 ? t('ach_unlocked') : t('ach_unlocked_n') }),
                    h('ul', { class: 'trp-ach__list' }, awards.map(function (a) {
                        var ic = /^[a-z0-9-]+$/.test(String(a.icon || '')) ? 'fa-' + String(a.icon).replace(/^fa-/, '') : 'fa-medal';
                        var medal = h('span', { class: 'trp-ach__medal', 'aria-hidden': 'true' }, icon(ic));
                        if (/^#[0-9a-fA-F]{6}$/.test(String(a.color || ''))) { medal.style.setProperty('--trp-ach', String(a.color)); }
                        return h('li', { class: 'trp-ach__item' }, [medal, h('div', null, [
                            h('strong', { class: 'trp-ach__name', text: String(a.name || '') }),
                            a.description ? h('div', { class: 'trp-muted', text: String(a.description) }) : h('div', { class: 'trp-muted', text: t('ach_added') })
                        ])]);
                    }))
                ]);
            } else {
                ach = null;
            }
            // "Achievements unlocked" shows only when something was unlocked.
            parts.push(h('div', { class: 'trp-result__grid' + (ach ? '' : ' is-single') }, [reviewCard, ach]));
            var retry = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--lg', hidden: passed || (isKiosk && triesLeft === 0) }, [icon('fa-redo'), t('retry')]);
            var cont = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--lg' }, [t('continue'), icon('fa-arrow-right')]);
            retry.addEventListener('click', function () { openLesson(l.uid, isCheck ? { check: true } : null); });
            cont.addEventListener('click', function () {
                var idx = order.indexOf(l.uid);
                var n = order[idx + 1];
                if (n && !isLocked(n)) { openLesson(n); return; }
                if (order.every(function (u) { return progress.done[u] || !byUid[u].required; })) { finishCourse(res); return; }
                renderHome();
            });
            var actions = [retry, cont];
            var footText = t('nothing_recorded');
            if (isKiosk) {
                footText = kx && kx.signLabel ? t('k_score_saved') : t('k_score_recorded');
                // A quick check never goes on the training record (the record's score is the final exam's).
                if (isCheck) { footText = passed && kx && kx.signLabel ? t('qc_passed_sign') : t('qc_not_record'); }
                if (passed || kLocked) { retry.hidden = true; }
                if (!passed && !kLocked && isCheck && !mustPass) {
                    // An optional quick check: try again while tries remain, or go on (the lesson is done).
                    retry.hidden = triesLeft === 0;
                    var nx = order[order.indexOf(l.uid) + 1];
                    if (nx && !isLocked(nx)) { clear(cont); cont.appendChild(document.createTextNode(t('next_lesson'))); cont.appendChild(icon('fa-arrow-right')); }
                    actions = [retry, cont];
                } else if (!passed && !kLocked) {
                    retry.className = 'trp-btn trp-btn--primary trp-btn--lg';
                    cont = h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--lg', on: { click: function () { renderHome(); } } }, [icon('fa-list-ul'), t('back_to_course')]);
                    actions = [retry, cont];
                }
                if (kLocked) {
                    cont = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--lg', on: { click: function () { renderHome(); } } }, [icon('fa-list-ul'), t('back_to_course')]);
                    actions = [retry, cont];
                }
                if (kx && kx.signLabel && fn('onSign')) {
                    var signBtn = h('button', { type: 'button', class: 'trp-btn trp-btn--primary trp-btn--lg' }, [icon('fa-pen-nib'), String(kx.signLabel)]);
                    signBtn.addEventListener('click', function () { setBusy(signBtn, true); adapter.onSign(res); });
                    cont.className = 'trp-btn trp-btn--ghost trp-btn--lg';
                    cont.hidden = true;
                    actions = [retry, cont, signBtn];
                }
            }
            var footer = h('footer', { class: 'trp-foot trp-foot--result' }, [
                h('div', { class: 'trp-foot__status' }, [icon('fa-info-circle'), h('span', { text: footText })]),
                h('div', { class: 'trp-foot__actions' }, actions)
            ]);
            screen.appendChild(h('div', { class: 'trp-lesson trp-lesson--result' }, [h('div', { class: 'trp-lscroll' }, h('div', { class: 'trp-page' }, parts)), footer]));
            var ttl = hero.querySelector('.trp-result__title');
            if (ttl) { ttl.tabIndex = -1; ttl.focus({ preventScroll: true }); }
        }

        function stat(label, value) { return h('div', { class: 'trp-stat' }, [h('span', { class: 'trp-stat__label', text: label }), h('strong', { class: 'trp-stat__value', text: value })]); }
        function fmtTook(ms) { var s = Math.round(ms / 1000); return s < 60 ? t('under_minute') : t('minutes', { n: Math.round(s / 60) }); }
        function confetti() {
            var box = h('div', { class: 'trp-confetti', 'aria-hidden': 'true' });
            var colors = ['var(--trp-accent)', '#16a34a', '#2563eb', '#d97706', '#7c3aed', '#0891b2'];
            for (var i = 0; i < 26; i++) {
                box.appendChild(h('span', {
                    class: 'trp-confetti__p', style: {
                        left: Math.round(Math.random() * 100) + '%', background: colors[i % colors.length],
                        'animation-delay': (Math.random() * 0.6).toFixed(2) + 's', '--x': String(Math.round(Math.random() * 240 - 120))
                    }
                }));
            }
            return box;
        }

        // ---------------- completion ----------------
        function renderComplete() {
            runCleanup();
            clear(screen);
            var c = view.course || {};
            var name = adapter.learnerName || t('learner');
            var date = new Date().toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' });
            var parts = [
                h('span', { class: 'trp-complete__badge', 'aria-hidden': 'true' }, icon('fa-check')),
                h('h1', { class: 'trp-complete__title', text: t('course_complete') }),
                h('p', { class: 'trp-complete__sub', text: t('complete_sub', { course: c.name || '' }) })
            ];
            if (c.attestation_text || c.requires_signature) {
                parts.push(h('section', { class: 'trp-card trp-attest' }, [
                    h('div', { class: 'trp-kicker', text: t('attestation') }),
                    h('p', { class: 'trp-attest__text', text: c.attestation_text || t('attest_default') }),
                    h('div', { class: 'trp-attest__meta' }, [h('span', { text: t('attest_by', { name: name }) }), h('span', { text: t('completed_on', { date: date }) })])
                ]));
            }
            parts.push(h('div', { class: 'trp-complete__actions' }, [
                h('button', { type: 'button', class: 'trp-btn trp-btn--ghost trp-btn--lg', on: { click: renderHome } }, [icon('fa-list-ul'), t('back_to_course')])
            ]));
            screen.appendChild(h('div', { class: 'trp-page trp-complete' }, parts));
        }

        // ---------------- start ----------------
        var initial = adapter.initialLesson && byUid[adapter.initialLesson] ? adapter.initialLesson : null;
        var initialCheck = adapter.initialCheck && checkOf(byUid[adapter.initialCheck]) && isCredited(adapter.initialCheck) ? adapter.initialCheck : null;
        if (initialCheck) { openLesson(initialCheck, { check: true }); } else if (initial) { openLesson(initial); } else { renderHome(); }

        return {
            destroy: function () { runCleanup(); clear(root); root.classList.remove('trp'); },
            open: openLesson,
            home: renderHome,
            reset: function () { progress = normaliseProgress(null); saveProgress(); renderHome(); },
            progress: function () { return JSON.parse(JSON.stringify(progress)); }
        };
    }

    // ------------------------------------------------------------------------------------------
    // TrainingPlayer.renderQuestion - the builder's live learner-look preview
    // ------------------------------------------------------------------------------------------
    function renderQuestion(container, q, opts) {
        opts = opts || {};
        var lang = opts.lang || 'en';
        var t = makeT(opts.strings || {}, lang);
        clear(container);
        if (!q) { return { destroy: function () { clear(container); } }; }
        var clean = {
            uid: q.uid || 'preview', type: q.type === 'multi' ? 'multi' : (q.type === 'truefalse' ? 'truefalse' : 'single'),
            text: String(q.text || ''), image_url: q.image_url || null,
            options: (q.options || []).map(function (o, i) { return { uid: o.uid || ('o' + i), text: String(o.text || '') }; })
        };
        var total = Math.max(1, opts.total || 1);
        var index = Math.max(0, Math.min(total - 1, opts.index || 0));
        var qc = questionCard(clean, { selected: [] }, { t: t, index: index, total: total, compact: true });
        var root = h('div', { class: 'trp trp--mini', lang: lang }, [
            h('header', { class: 'trp-top trp-top--mini' }, [
                h('div', { class: 'trp-top__brand' }, [h('span', { class: 'trp-top__mark', 'aria-hidden': 'true' }, icon('fa-hard-hat')), h('span', { class: 'trp-top__name' }, [opts.brand ? opts.brand + ' ' : '', h('span', { class: 'trp-top__accent', text: 'Training' })])]),
                h('span', { class: 'trp-seg trp-seg--static' }, h('span', { class: 'trp-seg__btn is-on', text: lang.toUpperCase() }))
            ]),
            h('div', { class: 'trp-mini' }, [
                h('div', { class: 'trp-mini__head' }, [h('span', { class: 'trp-muted', text: opts.title || '' }), h('strong', { text: t('question_of', { n: index + 1, total: total }) })]),
                h('div', { class: 'trp-bar trp-bar--sm' }, h('span', { class: 'trp-bar__fill', style: { width: Math.round((index + 1) * 100 / total) + '%' } })),
                qc.el,
                h('div', { class: 'trp-mini__foot' }, h('span', { class: 'trp-btn trp-btn--primary trp-btn--sm', 'aria-hidden': 'true', text: t('next') }))
            ])
        ]);
        container.appendChild(root);
        return { destroy: function () { clear(container); } };
    }

    window.TrainingPlayer = { mount: mount, renderQuestion: renderQuestion };
})();
