import { EyeIcon, FlagIcon, SparklesIcon } from '@heroicons/react/24/outline';
import { APP_NAME } from '../lib/app';

const SECTIONS = [
    {
        icon: SparklesIcon,
        title: 'Descripción',
        text: 'Sistema web para el registro y control del ingreso y entrega de vehículos. Centraliza las operaciones de los doctores operadores, el monitoreo por parte del Jefe y la gestión de usuarios y servicios a cargo de la Administración, ofreciendo reportes y estadísticas en tiempo real.',
    },
    {
        icon: FlagIcon,
        title: 'Misión',
        text: 'Agilizar y transparentar el flujo de vehículos mediante una plataforma confiable, moderna y fácil de usar, que garantice información precisa y oportuna para la toma de decisiones.',
    },
    {
        icon: EyeIcon,
        title: 'Visión',
        text: 'Ser la referencia en gestión vehicular, evolucionando constantemente para ofrecer una experiencia integral que integre nuevas tecnologías y se adapte a las necesidades de sus usuarios.',
    },
];

export default function About() {
    return (
        <div className="mx-auto max-w-3xl space-y-4">
            <div className="card flex flex-col items-center gap-4 p-8 text-center">
                <div className="relative">
                    <div className="absolute -inset-2 rounded-full bg-gradient-to-tr from-primary-600 to-pink-300 opacity-30 blur-lg" />
                    <img
                        src="/android-chrome-512x512.png"
                        alt={APP_NAME}
                        className="relative h-24 w-24 rounded-2xl shadow-lg"
                    />
                </div>
                <div>
                    <h1 className="text-2xl font-bold text-primary-700 dark:text-primary-300">{APP_NAME}</h1>
                    <p className="mt-1 text-sm text-stone-500 dark:text-wa-muted">Gestión de ingreso y entrega de vehículos</p>
                </div>
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                {SECTIONS.map((s) => (
                    <div key={s.title} className="card flex flex-col gap-3 p-5">
                        <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-200">
                            <s.icon className="h-5 w-5" />
                        </span>
                        <h2 className="font-semibold">{s.title}</h2>
                        <p className="text-sm text-stone-600 dark:text-wa-muted">{s.text}</p>
                    </div>
                ))}
            </div>
        </div>
    );
}