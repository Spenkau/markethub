# MarketHub frontend

Vue 3, TypeScript, Vite, Pinia, Vue Router и Lucide. Приложение использует same-origin Laravel API `/api/v1` с Sanctum cookie session и CSRF. Секреты интеграций во frontend не хранятся.

Полные требования, запуск, тестовая база и ограничения описаны в [основном README](../README.md); production — в [DEPLOYMENT](../docs/DEPLOYMENT.md).

```sh
npm ci
npm run dev
npm run typecheck
npm run lint
npm test
npm run build
```

Vite работает на 127.0.0.1:5173, API target по умолчанию `http://markethub.test`; для другого backend задайте `MARKETHUB_API_TARGET`. Статическая сборка находится в `dist`, Nginx должен направлять API/CSRF в Laravel и возвращать index.html для остальных deep links.

`npm run test:e2e:regression` запускает общую браузерную регрессию. `npm run test:e2e -- --config=playwright.visual.config.ts` создаёт screenshots шести viewport в docs/assets/visual и обновляет настоящий dashboard preview landing. Требуются Chrome, PHP в PATH (или PHP_BINARY), установленный vendor и отдельная PostgreSQL `_test` база в `.env.testing`. Браузерные тесты и backend suite запускаются последовательно, база пересоздаётся; рабочие данные использовать запрещено.

Дизайн-система: `src/assets/main.css` (токены, primitives и shell), `workspace.css` (раскладки предметных страниц), `features/onboarding/onboarding.css` (обучение). Inter размещён локально в public/fonts; лицензии reference и шрифта — в docs/licenses. Не добавляйте вторую конфликтующую тему.

Demo login использует серверный POST, не подставляет токены и не обходит CSRF. Demo readonly скрывает действия записи, а сервер независимо отклоняет их. Прогресс demo tours сохраняется только в sessionStorage.
