export type RegionalFormats = {locale:string,date:string,time:string,am:string,pm:string};
// Windows regional patterns use quoted literals and repeated date/time letters.
export function windowsDatePattern(date: Date, pattern: string, settings: RegionalFormats): string {
    const pad=(n:number)=>String(n).padStart(2,'0');
    const intl=(options:Intl.DateTimeFormatOptions)=>new Intl.DateTimeFormat(settings.locale,options).format(date);
    const hour=date.getHours(), period=hour<12?settings.am:settings.pm;
    const values:Record<string,string>={d:String(date.getDate()),dd:pad(date.getDate()),M:String(date.getMonth()+1),MM:pad(date.getMonth()+1),y:String(date.getFullYear()%100),yy:pad(date.getFullYear()%100),yyyy:String(date.getFullYear()),H:String(hour),HH:pad(hour),h:String(hour%12||12),hh:pad(hour%12||12),m:String(date.getMinutes()),mm:pad(date.getMinutes()),s:String(date.getSeconds()),ss:pad(date.getSeconds()),t:period.slice(0,1),tt:period};
    return pattern.replace(/'([^']|'')*'|d{1,4}|M{1,4}|y{1,4}|H{1,2}|h{1,2}|m{1,2}|s{1,2}|t{1,2}/g, token=>token.startsWith("'") ? token.slice(1,-1).replace(/''/g,"'") : token === 'ddd' || token === 'dddd' ? intl({weekday:token === 'ddd' ? 'short' : 'long'}) : token === 'MMM' || token === 'MMMM' ? intl({month:token === 'MMM' ? 'short' : 'long'}) : values[token] ?? token);
}
