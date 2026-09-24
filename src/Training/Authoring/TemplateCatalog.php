<?php

namespace ITFlow\Training\Authoring;

/**
 * Course templates (plan A18): five generic course shapes and six "Safety starter" outlines.
 *
 * Templates carry EN and ES HEADINGS AND HINTS ONLY - section titles and lesson titles, plus a
 * hint per lesson that is shown in the New course window's outline preview and never stored in
 * the course. The owner writes every word of the content. Safety starters also carry
 * SUGGESTED, EDITABLE defaults (regulation reference, validity, practical evaluation), which
 * the Safety Manager confirms in the course settings; nothing here is presented to learners
 * as fact.
 *
 * Template shape:
 *   key, group ('template'|'safety'), kind ('training'|'document'), icon (Core\Icons), color,
 *   category (suggested seeded category name, or null), name{en,es}, description{en,es},
 *   notes{en,es}|null, defaults{regulation_ref?, validity_months?, requires_signature?,
 *   needs_online?, needs_session?, needs_practical?, is_qualification?},
 *   sections[{title{en,es}, lessons[{type, role?, title{en,es}, hint{en,es}}]}]
 *
 * A document-kind template's sections are a preview only: the document shape (one document
 * lesson plus the acknowledgment) is created by CourseService::create().
 */
final class TemplateCatalog
{
    public const LANGS = ['en', 'es'];

    public static function all(): array
    {
        static $all = null;
        return $all ??= self::build();
    }

    public static function get(string $key): ?array
    {
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }

    private static function build(): array
    {
        $exam = self::section('Final exam', 'Examen final', [
            self::lesson('quiz', 'Final exam', 'Examen final',
                'Questions on the key points. Mark the ones that must not be missed as critical.',
                'Preguntas sobre los puntos clave. Marque como críticas las que no se pueden fallar.', 'exam'),
        ]);
        $checklistNote = [
            'en' => 'Write the practical evaluation checklist under Settings › Completion rules before publishing.',
            'es' => 'Escriba la lista de la evaluación práctica en Configuración › Reglas de finalización antes de publicar.',
        ];

        return [
            // ---------------------------------------------------------------- generic templates
            self::template('required_document', 'template', 'document', 'clipboard-check', '#0891B2', null,
                ['en' => 'Required document', 'es' => 'Documento obligatorio'],
                ['en' => 'Policy or SOP to read and sign', 'es' => 'Política o procedimiento para leer y firmar'],
                null,
                ['requires_signature' => true],
                [self::section('Document', 'Documento', [
                    self::lesson('document', 'The document', 'El documento',
                        'Upload the PDF, or write or import the text as an article.',
                        'Suba el PDF, o escriba o importe el texto como un artículo.'),
                    self::lesson('acknowledgment', 'Acknowledgment', 'Reconocimiento',
                        'People confirm they have read it and will follow it, then sign.',
                        'Las personas confirman que lo leyeron y que lo cumplirán, y luego firman.'),
                ])]),

            self::template('toolbox_talk', 'template', 'training', 'hands-helping', '#16A34A', 'Safety',
                ['en' => 'Toolbox talk', 'es' => 'Charla de seguridad'],
                ['en' => 'Short group session with sign-in', 'es' => 'Sesión grupal corta con registro de asistencia'],
                [
                    'en' => 'Group sessions and sign-in sheets arrive with trainer mode. The lessons here are the talk material.',
                    'es' => 'Las sesiones grupales y las hojas de asistencia llegan con el modo instructor. Estas lecciones son el material de la charla.',
                ],
                ['needs_online' => false, 'needs_session' => true],
                [self::section('The talk', 'La charla', [
                    self::lesson('article', 'Topic and why it matters', 'El tema y por qué importa',
                        'One page: the hazard, a recent example, what we expect.',
                        'Una página: el peligro, un ejemplo reciente y lo que esperamos.'),
                    self::lesson('article', 'Key points', 'Puntos clave',
                        'Three to five short rules people can repeat back.',
                        'De tres a cinco reglas cortas que las personas puedan repetir.'),
                    self::lesson('article', 'Discussion questions', 'Preguntas para conversar',
                        'Questions to ask the crew at the end.',
                        'Preguntas para hacerle al equipo al final.'),
                ])]),

            self::template('safety_course_quiz', 'template', 'training', 'shield-alt', '#DC2626', 'Safety',
                ['en' => 'Safety course + quiz', 'es' => 'Curso de seguridad con examen'],
                ['en' => 'Lessons with a final exam', 'es' => 'Lecciones con un examen final'],
                null,
                ['requires_signature' => true],
                [
                    self::section('Introduction', 'Introducción', [
                        self::lesson('article', 'Why this matters', 'Por qué es importante',
                            'The hazard, who is affected and what can go wrong.',
                            'El peligro, a quién afecta y qué puede salir mal.'),
                    ]),
                    self::section('The rules', 'Las reglas', [
                        self::lesson('article', 'What we do here', 'Lo que hacemos aquí',
                            'Your site procedure in plain words.',
                            'El procedimiento de su planta en palabras sencillas.'),
                        self::lesson('video', 'See it done', 'Véalo en práctica',
                            'A short video of the task done right.',
                            'Un video corto de la tarea hecha correctamente.'),
                    ]),
                    $exam,
                ]),

            self::template('equipment_certification', 'template', 'training', 'tools', '#D97706', 'Equipment',
                ['en' => 'Equipment certification', 'es' => 'Certificación de equipo'],
                ['en' => 'Online + hands-on evaluation', 'es' => 'En línea + evaluación práctica'],
                $checklistNote,
                ['needs_practical' => true, 'is_qualification' => true, 'requires_signature' => true],
                [
                    self::section('Know the equipment', 'Conozca el equipo', [
                        self::lesson('article', 'Controls and features', 'Controles y características',
                            'Photos of the controls with what each one does.',
                            'Fotos de los controles y lo que hace cada uno.'),
                        self::lesson('document', "Operator's manual", 'Manual del operador',
                            'The manufacturer manual, or the pages that matter, as a PDF.',
                            'El manual del fabricante, o las páginas importantes, en PDF.'),
                    ]),
                    self::section('Safe operation', 'Operación segura', [
                        self::lesson('article', 'Before you start', 'Antes de empezar',
                            'The pre-use inspection and when to tag the equipment out.',
                            'La inspección antes del uso y cuándo etiquetar el equipo como fuera de servicio.'),
                        self::lesson('video', 'Operating safely', 'Cómo operar con seguridad',
                            'A walk-through of normal operation.',
                            'Un recorrido por la operación normal.'),
                    ]),
                    self::section('Knowledge test', 'Prueba de conocimientos', [
                        self::lesson('quiz', 'Knowledge test', 'Prueba de conocimientos',
                            'Passing this comes before the hands-on evaluation.',
                            'Aprobar esta prueba va antes de la evaluación práctica.', 'exam'),
                    ]),
                ]),

            self::template('annual_refresher', 'template', 'training', 'check-double', '#7C3AED', null,
                ['en' => 'Annual refresher', 'es' => 'Repaso anual'],
                ['en' => 'A short yearly reminder with a quick check', 'es' => 'Un recordatorio anual corto con una evaluación rápida'],
                null,
                ['validity_months' => 12],
                [self::section('Refresher', 'Repaso', [
                    self::lesson('video', 'What to remember', 'Qué recordar',
                        'Two to five minutes on the rules people forget.',
                        'De dos a cinco minutos sobre las reglas que se olvidan.'),
                    self::lesson('article', "What's changed this year", 'Qué cambió este año',
                        'New equipment, new procedures or lessons from incidents.',
                        'Equipo nuevo, procedimientos nuevos o lecciones de incidentes.'),
                    self::lesson('quiz', 'Quick check', 'Evaluación rápida',
                        'Three to five questions.',
                        'De tres a cinco preguntas.'),
                ])]),

            // ---------------------------------------------------------------- safety starters
            self::template('hazcom', 'safety', 'training', 'exclamation-triangle', '#DC2626', 'Safety',
                ['en' => 'Hazard Communication (HazCom)', 'es' => 'Comunicación de peligros (HazCom)'],
                ['en' => 'Right to know: labels, Safety Data Sheets and our written program', 'es' => 'Derecho a saber: etiquetas, hojas de datos de seguridad y nuestro programa escrito'],
                null,
                ['regulation_ref' => '1910.1200(h)', 'validity_months' => null, 'requires_signature' => true],
                [
                    self::section('Your right to know', 'Su derecho a saber', [
                        self::lesson('article', 'What HazCom covers', 'Qué cubre HazCom',
                            'Which chemicals, which jobs, and why OSHA requires this training.',
                            'Qué químicos, qué trabajos y por qué OSHA exige esta capacitación.'),
                        self::lesson('document', 'Our written HazCom program', 'Nuestro programa escrito de HazCom',
                            'Upload the written program as a PDF and say where the paper copy is kept.',
                            'Suba el programa escrito en PDF e indique dónde se guarda la copia impresa.'),
                    ]),
                    self::section('Labels and pictograms', 'Etiquetas y pictogramas', [
                        self::lesson('article', 'Reading a label', 'Cómo leer una etiqueta',
                            'Product identifier, signal word, hazard statements and precautions.',
                            'Identificador del producto, palabra de advertencia, indicaciones de peligro y precauciones.'),
                        self::lesson('image', 'The pictograms', 'Los pictogramas',
                            'One image with the pictograms used on site.',
                            'Una imagen con los pictogramas que se usan en la planta.'),
                    ]),
                    self::section('Safety Data Sheets', 'Hojas de datos de seguridad', [
                        self::lesson('article', 'Finding what you need in an SDS', 'Cómo encontrar lo que necesita en una hoja de datos',
                            'Which sections to read first in an emergency.',
                            'Qué secciones leer primero en una emergencia.'),
                        self::lesson('article', 'Where our SDSs are', 'Dónde están nuestras hojas de datos',
                            'Binder locations or the online link, and who keeps them current.',
                            'La ubicación de las carpetas o el enlace en línea, y quién las mantiene al día.'),
                    ]),
                    self::section('Protecting yourself', 'Cómo protegerse', [
                        self::lesson('article', 'Chemicals in your work area', 'Químicos en su área de trabajo',
                            'The products people actually use, and the PPE for each.',
                            'Los productos que realmente se usan y el EPP para cada uno.'),
                        self::lesson('article', 'Spills and exposure', 'Derrames y exposición',
                            'What to do, who to call and where the eyewash is.',
                            'Qué hacer, a quién llamar y dónde está el lavaojos.'),
                    ]),
                    $exam,
                ]),

            self::template('loto', 'safety', 'training', 'lock', '#DC2626', 'Safety',
                ['en' => 'Lockout/Tagout (LOTO)', 'es' => 'Bloqueo y etiquetado (LOTO)'],
                ['en' => 'Energy control for authorized employees', 'es' => 'Control de energía para empleados autorizados'],
                [
                    'en' => 'The annual periodic inspection of each procedure (1910.147(c)(6)) is separate from this training.',
                    'es' => 'La inspección periódica anual de cada procedimiento (1910.147(c)(6)) es independiente de esta capacitación.',
                ],
                ['regulation_ref' => '1910.147(c)(7)', 'validity_months' => null, 'requires_signature' => true],
                [
                    self::section('Hazardous energy', 'Energía peligrosa', [
                        self::lesson('article', 'What lockout prevents', 'Qué previene el bloqueo',
                            'Unexpected start-up and stored energy, with a real example.',
                            'El arranque inesperado y la energía almacenada, con un ejemplo real.'),
                        self::lesson('article', 'Energy sources at our site', 'Fuentes de energía en nuestras instalaciones',
                            'Electrical, pneumatic, hydraulic, gravity, springs and heat.',
                            'Eléctrica, neumática, hidráulica, por gravedad, de resortes y térmica.'),
                    ]),
                    self::section('Our energy control program', 'Nuestro programa de control de energía', [
                        self::lesson('document', 'Energy control procedures', 'Procedimientos de control de energía',
                            'The written program or the machine-specific procedures as a PDF.',
                            'El programa escrito o los procedimientos de cada máquina en PDF.'),
                        self::lesson('article', 'Authorized and affected employees', 'Empleados autorizados y afectados',
                            'Who may lock out, and what everyone else must do.',
                            'Quién puede aplicar el bloqueo y qué deben hacer los demás.'),
                    ]),
                    self::section('Applying lockout', 'Cómo aplicar el bloqueo', [
                        self::lesson('video', 'Lockout step by step', 'El bloqueo paso a paso',
                            'Prepare, notify, shut down, isolate, lock, release stored energy.',
                            'Preparar, avisar, apagar, aislar, bloquear y liberar la energía almacenada.'),
                        self::lesson('article', 'Verifying zero energy', 'Cómo verificar la energía cero',
                            'Try the start button; test before you touch.',
                            'Pruebe el botón de arranque; verifique antes de tocar.'),
                    ]),
                    self::section('Removing locks', 'Retiro de candados', [
                        self::lesson('article', 'Returning equipment to service', 'Cómo volver a poner el equipo en servicio',
                            'Clear tools and people, remove your own lock, notify.',
                            'Retire herramientas y personas, quite su propio candado y avise.'),
                        self::lesson('article', 'Shift changes and group lockout', 'Cambios de turno y bloqueo en grupo',
                            'How locks are handed over and how group lockboxes work.',
                            'Cómo se entregan los candados y cómo funcionan las cajas de bloqueo en grupo.'),
                    ]),
                    $exam,
                ]),

            self::template('ppe', 'safety', 'training', 'hard-hat', '#DC2626', 'Safety',
                ['en' => 'Personal Protective Equipment (PPE)', 'es' => 'Equipo de protección personal (EPP)'],
                ['en' => 'When PPE is needed, how to wear it and its limits', 'es' => 'Cuándo se necesita el EPP, cómo usarlo y sus limitaciones'],
                null,
                ['regulation_ref' => '1910.132(f)', 'validity_months' => null, 'requires_signature' => true],
                [
                    self::section('When PPE is required', 'Cuándo se requiere el EPP', [
                        self::lesson('article', 'Our hazard assessment', 'Nuestra evaluación de peligros',
                            'Which areas and tasks need which PPE.',
                            'Qué áreas y tareas necesitan qué EPP.'),
                        self::lesson('article', 'The last line of defense', 'La última línea de defensa',
                            'Why guards and procedures come first.',
                            'Por qué las guardas y los procedimientos van primero.'),
                    ]),
                    self::section('Choosing the right PPE', 'Cómo elegir el EPP correcto', [
                        self::lesson('article', 'Eyes and face', 'Ojos y cara',
                            'Safety glasses, goggles, face shields and welding lenses.',
                            'Lentes de seguridad, gafas, caretas y lentes para soldar.'),
                        self::lesson('article', 'Hands and feet', 'Manos y pies',
                            'Glove types for cuts, chemicals and heat; safety footwear.',
                            'Tipos de guantes para cortes, químicos y calor; calzado de seguridad.'),
                        self::lesson('article', 'Head and hearing', 'Cabeza y oídos',
                            'Hard hats, earplugs and earmuffs.',
                            'Cascos, tapones y orejeras.'),
                    ]),
                    self::section('Wearing and caring for PPE', 'Uso y cuidado del EPP', [
                        self::lesson('video', 'Putting it on and taking it off', 'Cómo ponérselo y quitárselo',
                            'Fit and order, shown on real equipment.',
                            'Ajuste y orden, mostrados con equipo real.'),
                        self::lesson('article', 'Inspect, clean, replace', 'Inspeccionar, limpiar y reemplazar',
                            'Signs of damage and where to get replacements.',
                            'Señales de daño y dónde conseguir repuestos.'),
                    ]),
                    $exam,
                ]),

            self::template('fire_extinguisher', 'safety', 'training', 'fire-extinguisher', '#DC2626', 'Safety',
                ['en' => 'Portable fire extinguishers', 'es' => 'Extintores portátiles'],
                ['en' => 'Fire classes, the PASS technique and when to leave', 'es' => 'Clases de fuego, la técnica PASS y cuándo evacuar'],
                null,
                ['regulation_ref' => '1910.157(g)', 'validity_months' => 12, 'requires_signature' => true],
                [
                    self::section('Fire basics', 'Conceptos básicos del fuego', [
                        self::lesson('article', 'How fires start and spread', 'Cómo empieza y se propaga el fuego',
                            'The fire triangle in plain words.',
                            'El triángulo del fuego en palabras sencillas.'),
                        self::lesson('article', 'Classes of fire', 'Clases de fuego',
                            'A, B, C, D and K, and which ones could happen here.',
                            'A, B, C, D y K, y cuáles podrían ocurrir aquí.'),
                    ]),
                    self::section('Our extinguishers', 'Nuestros extintores', [
                        self::lesson('image', 'Where the extinguishers are', 'Dónde están los extintores',
                            'A floor plan or photos of each location.',
                            'Un plano o fotos de cada ubicación.'),
                        self::lesson('article', 'Reading the label and gauge', 'Cómo leer la etiqueta y el manómetro',
                            'Rating, class symbols and what a charged gauge looks like.',
                            'La clasificación, los símbolos de clase y cómo se ve un manómetro cargado.'),
                    ]),
                    self::section('Using an extinguisher', 'Cómo usar un extintor', [
                        self::lesson('video', 'The PASS technique', 'La técnica PASS',
                            'Pull, Aim, Squeeze, Sweep.',
                            'Jalar, Apuntar, Apretar, Barrer.'),
                        self::lesson('article', 'Fight or leave?', '¿Combatir o evacuar?',
                            'When a fire is too big, and our evacuation route.',
                            'Cuándo un fuego es demasiado grande y cuál es nuestra ruta de evacuación.'),
                    ]),
                    $exam,
                ]),

            self::template('forklift', 'safety', 'training', 'truck-loading', '#D97706', 'Equipment',
                ['en' => 'Forklift operator', 'es' => 'Operador de montacargas'],
                ['en' => 'Truck and workplace topics before the hands-on evaluation', 'es' => 'Temas del montacargas y del lugar de trabajo antes de la evaluación práctica'],
                $checklistNote,
                ['regulation_ref' => '1910.178(l)', 'validity_months' => 36, 'needs_practical' => true, 'requires_signature' => true],
                [
                    self::section('The truck', 'El montacargas', [
                        self::lesson('article', 'Controls and instruments', 'Controles e instrumentos',
                            'Where they are, what they do and how they work.',
                            'Dónde están, qué hacen y cómo funcionan.'),
                        self::lesson('article', 'Stability and capacity', 'Estabilidad y capacidad',
                            'The stability triangle and reading the data plate.',
                            'El triángulo de estabilidad y cómo leer la placa de datos.'),
                        self::lesson('article', 'Operating limits and warnings', 'Límites de operación y advertencias',
                            "From the operator's manual for our trucks.",
                            'Del manual del operador de nuestros montacargas.'),
                    ]),
                    self::section('Our workplace', 'Nuestro lugar de trabajo', [
                        self::lesson('article', 'Floors, ramps and docks', 'Pisos, rampas y andenes',
                            'Surface conditions, grades and dock plates.',
                            'Condiciones del piso, pendientes y placas de andén.'),
                        self::lesson('article', 'Pedestrians and tight spaces', 'Peatones y espacios reducidos',
                            'Right of way, the horn, blind corners and narrow aisles.',
                            'Derecho de paso, el claxon, esquinas ciegas y pasillos angostos.'),
                        self::lesson('article', 'Charging and refueling', 'Carga de baterías y combustible',
                            'Where and how, and what to wear.',
                            'Dónde y cómo hacerlo, y qué equipo usar.'),
                    ]),
                    self::section('Daily inspection', 'Inspección diaria', [
                        self::lesson('document', 'Pre-use checklist', 'Lista de revisión antes del uso',
                            'The checklist operators fill in each shift.',
                            'La lista que los operadores llenan en cada turno.'),
                        self::lesson('video', 'Walk-around inspection', 'Inspección alrededor del equipo',
                            'A walk-around on one of our trucks.',
                            'Una revisión alrededor de uno de nuestros montacargas.'),
                    ]),
                    $exam,
                ]),

            self::template('overhead_crane', 'safety', 'training', 'industry', '#D97706', 'Equipment',
                ['en' => 'Overhead crane and rigging', 'es' => 'Grúa viajera y aparejos'],
                ['en' => 'Inspection, rigging and safe lifts before the hands-on evaluation', 'es' => 'Inspección, aparejos e izajes seguros antes de la evaluación práctica'],
                $checklistNote,
                ['regulation_ref' => '1910.179', 'validity_months' => null, 'needs_practical' => true, 'requires_signature' => true],
                [
                    self::section('The crane', 'La grúa', [
                        self::lesson('article', 'Crane parts and controls', 'Partes y controles de la grúa',
                            'Bridge, trolley, hoist, and the pendant or remote.',
                            'Puente, carro, polipasto y la botonera o el control remoto.'),
                        self::lesson('article', 'Rated capacity', 'Capacidad nominal',
                            'Where the capacity is marked and what counts toward it.',
                            'Dónde está marcada la capacidad y qué cuenta para ella.'),
                    ]),
                    self::section('Inspection', 'Inspección', [
                        self::lesson('document', 'Daily and pre-lift checks', 'Revisiones diarias y antes del izaje',
                            'Your checklist as a PDF.',
                            'Su lista de revisión en PDF.'),
                        self::lesson('article', 'Taking a crane out of service', 'Cómo retirar una grúa de servicio',
                            'What to report and how to tag it out.',
                            'Qué reportar y cómo etiquetarla fuera de servicio.'),
                    ]),
                    self::section('Rigging and lifting', 'Aparejos e izaje', [
                        self::lesson('article', 'Slings, hitches and hardware', 'Eslingas, amarres y accesorios',
                            'Inspecting slings and choosing a hitch.',
                            'Cómo inspeccionar eslingas y elegir un amarre.'),
                        self::lesson('image', 'Hand signals', 'Señales de mano',
                            'The standard signals your crews use.',
                            'Las señales estándar que usan sus equipos.'),
                        self::lesson('video', 'Making a safe lift', 'Cómo hacer un izaje seguro',
                            'Plan, rig, test lift, travel, land.',
                            'Planear, aparejar, hacer una prueba de izaje, trasladar y bajar la carga.'),
                    ]),
                    $exam,
                ]),
        ];
    }

    private static function template(string $key, string $group, string $kind, string $icon, string $color, ?string $category,
        array $name, array $description, ?array $notes, array $defaults, array $sections): array
    {
        $count = 0;
        foreach ($sections as $s) {
            $count += count($s['lessons']);
        }
        return [
            'key' => $key,
            'group' => $group,
            'kind' => $kind,
            'icon' => $icon,
            'color' => $color,
            'category' => $category,
            'name' => $name,
            'description' => $description,
            'notes' => $notes,
            'defaults' => $defaults,
            'sections' => $sections,
            'lesson_count' => $count,
        ];
    }

    private static function section(string $en, string $es, array $lessons): array
    {
        return ['title' => ['en' => $en, 'es' => $es], 'lessons' => $lessons];
    }

    private static function lesson(string $type, string $en, string $es, string $hintEn, string $hintEs, ?string $role = null): array
    {
        return ['type' => $type, 'role' => $type === 'quiz' ? ($role ?? 'standalone') : null,
                'title' => ['en' => $en, 'es' => $es], 'hint' => ['en' => $hintEn, 'es' => $hintEs]];
    }
}
