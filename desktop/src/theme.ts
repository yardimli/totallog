import './themes.css';
export const themes = [['light','☀️ Light'],['paper','🌿 Paper garden'],['blue','💧 Blue horizon'],['red','🔥 Red alert'],['dark','🌙 Dark']];
export function currentTheme() {
 const saved=localStorage.getItem('totallog.theme');
 return themes.some(([id])=>id===saved)?saved!:'dark';
}
export function applyTheme(theme:string) {
 if(!themes.some(([id])=>id===theme)) return;
 document.documentElement.dataset.theme=theme;
 document.documentElement.classList.toggle('dark',theme==='dark'||theme==='red');
 localStorage.setItem('totallog.theme',theme);
}
applyTheme(currentTheme());
