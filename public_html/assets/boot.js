// Runs before first paint (loaded synchronously in <head>, ahead of the
// stylesheet) so CSS can collapse the command toolbar into the ⋯ menu without
// a flash. Exists as its own file because the admin CSP allows 'self' scripts
// only: no inline <script> is permitted. Without JS this file never runs and the
// UI keeps its plain inline toolbar.
document.documentElement.classList.add('js');
