<?php
/**
 * Trial Account Management System
 * Checks if demo/trial accounts have expired
 */

if (!defined('APP_ACCESS')) {
    die('Direct access not permitted');
}

function checkTrialStatus($user) {
    if (!$user) return null;
    
    // Check if trial fields exist and user is trial account
    if (!isset($user['is_trial']) || $user['is_trial'] != 1) {
        return null;
    }
    
    // Check if trial expiry date exists
    if (!isset($user['trial_expires_at']) || empty($user['trial_expires_at'])) {
        return null;
    }
    
    $expiryDate = new DateTime($user['trial_expires_at']);
    $now = new DateTime();
    
    $expired = $now > $expiryDate;
    $daysLeft = $now->diff($expiryDate)->days;
    
    if ($now > $expiryDate) {
        $daysLeft = -$daysLeft; // Negative for expired
    }
    
    return [
        'is_expired' => $expired,
        'expiry_date' => $user['trial_expires_at'],
        'days_left' => $daysLeft,
        'formatted_expiry' => $expiryDate->format('d M Y')
    ];
}

function getTrialNotificationHtml($trialStatus, $waNumber = null) {
    if (!$trialStatus) return '';
    
    $isExpired = $trialStatus['is_expired'];
    $daysLeft = $trialStatus['days_left'];
    $formattedExpiry = $trialStatus['formatted_expiry'];
    
    if ($isExpired) {
        // Trial expired - show upgrade message
        $waLink = $waNumber ? "https://wa.me/{$waNumber}?text=Halo,%20saya%20ingin%20upgrade%20ke%20versi%20PRO" : '#';
        
        return '
        <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15); animation: slideInDown 0.5s ease-out;">
            <div style="display: flex; align-items: start; gap: 1rem;">
                <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-feather="alert-triangle" style="width: 24px; height: 24px; color: white;"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #991b1b; font-size: 1.25rem; margin-bottom: 0.5rem;">⚠️ Trial Expired!</div>
                    <div style="color: #b91c1c; font-size: 0.95rem; line-height: 1.6; margin-bottom: 1rem;">
                        Akun demo Anda telah <strong>berakhir sejak ' . $formattedExpiry . '</strong>. 
                        <br>Upgrade ke versi <strong>PRO</strong> untuk terus menggunakan semua fitur tanpa batas!
                    </div>
                    ' . ($waNumber ? '
                    <a href="' . $waLink . '" target="_blank" class="btn" style="background: linear-gradient(135deg, #25D366, #128C7E); color: white; font-weight: 600; padding: 0.75rem 1.5rem; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(37,211,102,0.3);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.304-1.654a11.882 11.882 0 005.713 1.456h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        <span>Hubungi Developer untuk Upgrade PRO</span>
                    </a>
                    ' : '') . '
                </div>
            </div>
        </div>';
    } else if ($daysLeft <= 7) {
        // Trial ending soon - show warning
        return '
        <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #f59e0b; padding: 1.25rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(245,158,11,0.15); animation: slideInDown 0.5s ease-out;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="width: 40px; height: 40px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-feather="clock" style="width: 20px; height: 20px; color: white;"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #92400e; font-size: 1rem; margin-bottom: 0.25rem;">⏰ Trial Akan Berakhir!</div>
                    <div style="color: #92400e; font-size: 0.875rem;">
                        Akun demo Anda akan berakhir dalam <strong>' . $daysLeft . ' hari</strong> (' . $formattedExpiry . ')
                    </div>
                </div>
            </div>
        </div>';
    }
    
    return '';
}
