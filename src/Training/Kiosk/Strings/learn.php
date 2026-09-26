<?php

/**
 * Kiosk learner-engine error strings (lane K3): the learner `err.*` codes of §4.4 that the
 * learner API returns. UI strings for the learner pages are K4's (learn_ui.php). Plain text only.
 */

return [
    'en' => [
        'err.lesson_locked' => 'Finish the lessons before this one first.',
        'err.exam_locked' => 'Finish every other lesson before the final exam.',
        'err.already_passed' => 'You already passed this quiz.',
        'err.attempts_exhausted' => 'No tries left. See your trainer.',
        'err.session_too_short' => 'Not enough time left in this sign-in for the timed quiz. Tap Done, sign in again, then start it.',
        'err.time_up' => 'Time is up for this quiz.',
        'err.attempt_closed' => 'This quiz attempt is already finished.',
        'err.quiz_empty' => 'This quiz has no questions. Tell your trainer.',
        'err.video_changed' => 'This video changed since the course was published. Tell your trainer.',
        'err.use_exam_start' => 'Start the quiz to finish this lesson.',
        'err.use_ack_sign' => 'Sign the acknowledgment to finish this lesson.',
        'err.run_locked' => 'This course is locked. See your trainer.',
        'err.run_blocked' => 'This course needs attention. See your trainer.',
        'err.run_closed' => 'This course run is already closed.',
        'err.no_online_part' => 'This course is done with your trainer, not on this device.',
        'err.prereq_missing' => 'Finish these courses first: {names}',
        'err.gate_not_met' => 'Spend a little more time on this lesson first.',
        'err.signature_invalid' => 'The signature could not be read. Clear it and sign again.',
        'err.signature_empty' => 'Please sign in the box.',
        'err.check_before_content' => 'Finish the lesson first, then take its quick check.',
        'err.check_pending' => "Pass the lesson's quick check first, then sign.",
    ],
    'es' => [
        'err.lesson_locked' => 'Primero termine las lecciones anteriores.',
        'err.exam_locked' => 'Termine todas las demás lecciones antes del examen final.',
        'err.already_passed' => 'Ya aprobó este examen.',
        'err.attempts_exhausted' => 'Ya no le quedan intentos. Hable con su instructor.',
        'err.session_too_short' => 'No queda suficiente tiempo en esta sesión para el examen con tiempo. Toque Salir, entre de nuevo y empiécelo.',
        'err.time_up' => 'Se acabó el tiempo de este examen.',
        'err.attempt_closed' => 'Este intento ya terminó.',
        'err.quiz_empty' => 'Este examen no tiene preguntas. Avísele a su instructor.',
        'err.video_changed' => 'Este video cambió desde que se publicó el curso. Avísele a su instructor.',
        'err.use_exam_start' => 'Empiece el examen para terminar esta lección.',
        'err.use_ack_sign' => 'Firme la confirmación para terminar esta lección.',
        'err.run_locked' => 'Este curso está bloqueado. Hable con su instructor.',
        'err.run_blocked' => 'Este curso necesita atención. Hable con su instructor.',
        'err.run_closed' => 'Este intento del curso ya está cerrado.',
        'err.no_online_part' => 'Este curso se hace con su instructor, no en este dispositivo.',
        'err.prereq_missing' => 'Primero termine estos cursos: {names}',
        'err.gate_not_met' => 'Dedique un poco más de tiempo a esta lección.',
        'err.signature_invalid' => 'No se pudo leer la firma. Bórrela y firme otra vez.',
        'err.signature_empty' => 'Por favor firme en el cuadro.',
        'err.check_before_content' => 'Primero termine la lección y luego haga su repaso rápido.',
        'err.check_pending' => 'Primero apruebe el repaso rápido de la lección y luego firme.',
    ],
];
