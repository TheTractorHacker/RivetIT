<?php

/**
 * Kiosk strings for an agent's "Reset progress" / "Reset (take again)" on an assignment (Kiosk\Learn\RunReset): the
 * 409 run_reset a kiosk showing that course gets on its next call, shown as a dialog before going back to the course
 * list; and the Learning Center / course page notices for someone who was not on the kiosk then. Plain text only.
 */

return [
    'en' => [
        'err.run_reset' => "Your progress on this course was reset by your supervisor. Start again when you're ready.",
        'reset.title' => 'Progress reset',
        'reset.back' => 'Back to my courses',
        // Learning Center / course page, for someone who was not on the kiosk when it happened (until they start again).
        'reset.notice_progress' => "Your supervisor reset your progress on {course} on {date}. Start again when you're ready.",
        'reset.notice_retake' => 'Your {course} record from {date} was voided by your supervisor. Please take it again by {due}.',
        'reset.notice_retake_nodate' => 'Your {course} record was voided by your supervisor. Please take it again by {due}.',
        'reset.course_progress' => "Your supervisor reset your progress on this course on {date}. Start again when you're ready.",
        'reset.course_retake_title' => 'Take this course again',
        'reset.course_retake' => 'Your record from {date} was voided by your supervisor. Please take it again by {due}.',
        'reset.course_retake_nodate' => 'Your record was voided by your supervisor. Please take it again by {due}.',
    ],
    'es' => [
        'err.run_reset' => 'Su supervisor reinició su avance en este curso. Vuelva a empezar cuando esté listo.',
        'reset.title' => 'Avance reiniciado',
        'reset.back' => 'Volver a mis cursos',
        'reset.notice_progress' => 'Su supervisor reinició su avance en {course} el {date}. Vuelva a empezar cuando esté listo.',
        'reset.notice_retake' => 'Su supervisor anuló su registro de {course} del {date}. Tómelo de nuevo a más tardar el {due}.',
        'reset.notice_retake_nodate' => 'Su supervisor anuló su registro de {course}. Tómelo de nuevo a más tardar el {due}.',
        'reset.course_progress' => 'Su supervisor reinició su avance en este curso el {date}. Vuelva a empezar cuando esté listo.',
        'reset.course_retake_title' => 'Tome este curso de nuevo',
        'reset.course_retake' => 'Su supervisor anuló su registro del {date}. Tómelo de nuevo a más tardar el {due}.',
        'reset.course_retake_nodate' => 'Su supervisor anuló su registro. Tómelo de nuevo a más tardar el {due}.',
    ],
];
