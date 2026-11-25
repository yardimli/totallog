import {invoke} from '@tauri-apps/api/core';
import {windowsDatePattern, type RegionalFormats} from './date-format';
let formats:RegionalFormats|null=null;
export const systemFormatsReady=invoke<RegionalFormats|null>('system_date_formats').then(value=>{formats=value;if(value?.locale) document.documentElement.lang=value.locale;}).catch(error=>{console.warn('Could not read Windows regional date/time settings:',error);});
const asDate=(value:Date|string|number)=>value instanceof Date?value:new Date(typeof value==='string' && /^\d{4}-\d{2}-\d{2}$/.test(value)?`${value}T12:00:00`:value);
export function systemDate(value:Date|string|number,options?:Intl.DateTimeFormatOptions) {
    const date=asDate(value);if(Number.isNaN(date.getTime()))return '';
    return !options && formats?.date ? windowsDatePattern(date,formats.date,formats) : date.toLocaleDateString(formats?.locale,options);
}
export function systemTime(value:Date|string|number) {
    const date=asDate(value);if(Number.isNaN(date.getTime()))return '';
    return formats?.time ? windowsDatePattern(date,formats.time,formats) : date.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'});
}
export const systemDateTime=(value:Date|string|number)=>`${systemDate(value)} ${systemTime(value)}`;
export function systemWallTime(value:string) {
    const [hour,minute]=value.split(':').map(Number), date=new Date();
    date.setHours(hour,minute,0,0);return systemTime(date);
}
