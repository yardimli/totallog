import {invoke} from '@tauri-apps/api/core';
const places = new Map<string, Promise<string>>();
function placeName(latitude: number, longitude: number): Promise<string> {
    const key = `${latitude.toFixed(5)},${longitude.toFixed(5)}`;
    if (!places.has(key)) {
        if (places.size >= 100) places.delete(places.keys().next().value!);
        places.set(key, invoke<{city?:string,suburb?:string}>('logging_online', {path:'location/reverse',data:{latitude,longitude}})
            .then(place => [place.suburb,place.city].filter(Boolean).join(', ')).catch(() => ''));
    }
    return places.get(key)!;
}
export function enhanceEntryLocation(dialog: HTMLDialogElement) {
    const output=dialog.querySelector<HTMLElement>('[data-location-status]');
    if (!output) return;
    const show = (latitude:number, longitude:number) => {
        output.textContent=`${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
        output.hidden=false;
        void placeName(latitude,longitude).then(name=>{if(name && dialog.isConnected) output.textContent=name;});
    };
    const input=dialog.querySelector<HTMLInputElement>('[name="__location"]');
    if(input && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(position=>{
            if(!dialog.isConnected || !dialog.open) return;
            const {latitude,longitude,accuracy}=position.coords;
            input.value=JSON.stringify({latitude,longitude,location_accuracy:Math.min(100000,accuracy)});
            show(latitude,longitude);
        },()=>{}, {enableHighAccuracy:true,timeout:10000,maximumAge:60000});
    } else if(output.dataset.latitude && output.dataset.longitude) {
        const latitude=Number(output.dataset.latitude),longitude=Number(output.dataset.longitude);
        if(Number.isFinite(latitude) && Number.isFinite(longitude)) show(latitude,longitude);
    }
}
