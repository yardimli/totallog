use serde_json::{json, Value};

#[tauri::command]
pub fn system_date_formats() -> Value {
    #[cfg(windows)]
    {
        use windows_sys::Win32::Globalization::{GetLocaleInfoEx, GetUserDefaultLocaleName, LOCALE_SSHORTDATE, LOCALE_SSHORTTIME, LOCALE_S1159, LOCALE_S2359};
        fn setting(kind: u32) -> String {
            let mut buffer = [0u16; 256];
            // Null locale means the current Windows user, including regional overrides.
            let len = unsafe { GetLocaleInfoEx(std::ptr::null(), kind, buffer.as_mut_ptr(), buffer.len() as i32) };
            if len > 0 { String::from_utf16_lossy(&buffer[..len as usize - 1]) } else { String::new() }
        }
        let mut locale = [0u16; 85];
        let len = unsafe { GetUserDefaultLocaleName(locale.as_mut_ptr(), locale.len() as i32) };
        let locale = if len > 0 { String::from_utf16_lossy(&locale[..len as usize - 1]) } else { "en-US".into() };
        json!({"locale":locale,"date":setting(LOCALE_SSHORTDATE),"time":setting(LOCALE_SSHORTTIME),"am":setting(LOCALE_S1159),"pm":setting(LOCALE_S2359)})
    }
    #[cfg(not(windows))]
    { json!(null) }
}

#[cfg(all(test, windows))]
mod tests {
    #[test]
    fn reads_current_windows_regional_overrides() {
        let formats = super::system_date_formats();
        for key in ["locale", "date", "time"] {
            assert!(!formats[key].as_str().unwrap().is_empty());
        }
    }
}
