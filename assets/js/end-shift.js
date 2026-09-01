/**
 * End Shift Modal Handler v2.1
 * Handles Daily Report, PO Images, and WhatsApp Integration
 * Updated: 2026-02-07 - Fixed formatCurrency conflict
 */

console.log('⚡ end-shift.js loaded!');

// Define initiateEndShift as global function
/**
 * End Shift Handler v3.0
 * Simplified: Direct PDF Print of Daily Report
 * Updated: 2026-02-07 - Removed WhatsApp API, direct print only
 */

console.log('⚡ end-shift.js v3.0 loaded!');

// Define initiateEndShift as global function
window.initiateEndShift = function initiateEndShift() {
    console.log('🚀 End Shift initiated - Opening print report...');
    
    // Open print report in new window/tab
    const printUrl = BASE_URL + '/print-end-shift-report.php';
    const printWindow = window.open(printUrl, '_blank', 'width=1024,height=768');
    
    if (!printWindow) {
        alert('Pop-up diblokir! Mohon izinkan pop-up untuk mencetak laporan.');
        console.error('❌ Pop-up blocked!');
    } else {
        console.log('✅ Print report window opened successfully');
        
        // Send notification to owner/admin
        sendEndShiftNotificationToOwner();
    }
};

// Send notification to owner when end-shift is done
async function sendEndShiftNotificationToOwner() {
    try {
        // Fetch today's summary
        const response = await fetch(BASE_URL + '/api/end-shift.php');
        const result = await response.json();
        
        if (result.status === 'success') {
            const data = result.data;
            
            // Send notification via API
            await fetch(BASE_URL + '/api/send-notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: 'end_shift',
                    data: {
                        cashier_name: data.user?.name || window.APP_USER_NAME || 'Kasir',
                        total_sales: data.daily_report?.total_income || 0,
                        total_expense: data.daily_report?.total_expense || 0,
                        net_balance: data.daily_report?.net_balance || 0,
                        transaction_count: data.daily_report?.transaction_count || 0,
                        date: data.daily_report?.date || new Date().toISOString().split('T')[0]
                    }
                })
            });
            
            console.log('📨 End-shift notification sent to owner');
        }
    } catch (e) {
        console.log('Notification send failed:', e);
    }
}

function showLoadingModal(message) {
    let modal = document.getElementById('loadingModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'loadingModal';
        document.body.appendChild(modal);
    }

    modal.innerHTML = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 9999;">
            <div style="background: white; padding: 2rem; border-radius: 8px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                <div style="width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #667eea; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 1rem;"></div>
                <p style="margin: 0; color: #333; font-size: 16px;">${message}</p>
            </div>
        </div>
        <style>
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    `;
}

function closeLoadingModal() {
    const modal = document.getElementById('loadingModal');
    if (modal) {
        modal.remove();
    }
}

function showEndShiftModal(data) {
    const daily = data.daily_report;
    const pos = data.pos_data;
    const user = data.user;
    const business = data.business;

    let modal = document.getElementById('endShiftModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'endShiftModal';
        document.body.appendChild(modal);
    }

    // Use formatCurrency from main.js if available, otherwise define locally
    const formatMoney = window.formatCurrency || function(amount) {
        return 'Rp ' + amount.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    };

    const dateStr = new Date(daily.date).toLocaleDateString('id-ID', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    let poHtml = '';
    if (pos.count > 0) {
        poHtml = `
            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #f0f0f0;">
                <h4 style="color: #333; margin-bottom: 1rem;">📦 Purchase Order Hari Ini (${pos.count})</h4>
                <div id="poGallery" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem;">
                    ${pos.list.map((p, idx) => `
                        <div style="border: 1px solid #ddd; border-radius: 8px; padding: 0.5rem; text-align: center; cursor: pointer;" onclick="viewPODetail(${idx})">
                            <div style="background: #f5f5f5; height: 120px; border-radius: 4px; display: flex; align-items: center; justify-content: center; margin-bottom: 0.5rem;">
                                ${p.image_path ? `<img src="${p.image_path}" alt="PO ${p.po_number}" style="max-width: 100%; max-height: 100%; object-fit: contain;">` : '<span style="color: #999; font-size: 14px;">No Image</span>'}
                            </div>
                            <small style="display: block; color: #666; margin-bottom: 0.25rem;">${p.po_number}</small>
                            <strong style="display: block; color: #333; font-size: 12px;">${p.supplier_name || 'Supplier'}</strong>
                            <small style="color: #667eea;">${formatMoney(p.total_amount)}</small>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    modal.innerHTML = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 20px; overflow-y: auto;">
            <div style="background: white; border-radius: 12px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
                <!-- Header -->
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 12px 12px 0 0; position: relative;">
                    <button onclick="closeEndShiftModal()" style="position: absolute; top: 1rem; right: 1rem; border: none; background: rgba(255,255,255,0.3); color: white; width: 40px; height: 40px; border-radius: 50%; font-size: 24px; cursor: pointer;">×</button>
                    <h2 style="margin: 0 0 0.5rem; font-size: 28px;">🌅 End Shift Report</h2>
                    <p style="margin: 0; opacity: 0.9;">${dateStr}</p>
                </div>

                <!-- Content -->
                <div style="padding: 2rem;">
                    <!-- User Info -->
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;">
                        <p style="margin: 0; color: #666;"><strong>Shift Officer:</strong> ${user.name}</p>
                        <p style="margin: 0.25rem 0 0; color: #666;"><strong>Business:</strong> ${business.name}</p>
                        <p style="margin: 0.25rem 0 0; color: #666;"><strong>Role:</strong> ${user.role}</p>
                    </div>

                    <!-- Daily Summary -->
                    <div style="margin-bottom: 2rem;">
                        <h3 style="color: #333; margin-bottom: 1rem; border-bottom: 2px solid #667eea; padding-bottom: 0.5rem;">💰 Ringkasan Transaksi</h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div style="background: #e8f5e9; padding: 1rem; border-radius: 8px; border-left: 4px solid #4caf50;">
                                <small style="color: #666;">Total Pemasukan</small>
                                <div style="font-size: 20px; font-weight: bold; color: #4caf50; margin-top: 0.5rem;">${formatMoney(daily.total_income)}</div>
                            </div>
                            <div style="background: #ffebee; padding: 1rem; border-radius: 8px; border-left: 4px solid #f44336;">
                                <small style="color: #666;">Total Pengeluaran</small>
                                <div style="font-size: 20px; font-weight: bold; color: #f44336; margin-top: 0.5rem;">${formatMoney(daily.total_expense)}</div>
                            </div>
                        </div>

                        <div style="background: ${daily.net_balance >= 0 ? '#e3f2fd' : '#fff3e0'}; padding: 1rem; border-radius: 8px; border-left: 4px solid ${daily.net_balance >= 0 ? '#2196f3' : '#ff9800'};">
                            <small style="color: #666;">Saldo Bersih</small>
                            <div style="font-size: 24px; font-weight: bold; color: ${daily.net_balance >= 0 ? '#2196f3' : '#ff9800'}; margin-top: 0.5rem;">${formatMoney(daily.net_balance)}</div>
                        </div>

                        <div style="margin-top: 1rem; padding: 1rem; background: #f5f5f5; border-radius: 8px;">
                            <small style="color: #666;">Jumlah Transaksi: <strong>${daily.transaction_count}</strong></small>
                        </div>
                    </div>

                    <!-- PO Section -->
                    ${poHtml}

                    <!-- Actions -->
                    <div style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #f0f0f0; display: flex; gap: 1rem;">
                        <button onclick="sendToWhatsApp('${daily.date}', ${daily.total_income}, ${daily.total_expense}, ${daily.net_balance}, '${user.name}', ${daily.transaction_count}, ${pos.count}, '${business.name}')" 
                                style="flex: 1; padding: 0.75rem; background: #25d366; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px;">
                            📱 Kirim ke WhatsApp GM/Admin
                        </button>
                        <button onclick="confirmLogout()" 
                                style="flex: 1; padding: 0.75rem; background: #667eea; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px;">
                            ✓ Logout & Selesai
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Add modal style if not exists
    if (!document.getElementById('endShiftStyle')) {
        const style = document.createElement('style');
        style.id = 'endShiftStyle';
        style.innerHTML = `
            #endShiftModal {
                animation: slideUp 0.3s ease-out;
            }
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
        document.head.appendChild(style);
    }
}

function closeEndShiftModal() {
    const modal = document.getElementById('endShiftModal');
    if (modal) {
        modal.style.animation = 'slideDown 0.3s ease-out forwards';
        setTimeout(() => modal.remove(), 300);
    }
}

function viewPODetail(index) {
    // This can be expanded to show full PO details in a separate modal
    alert('Lihat detail PO di dashboard untuk informasi lengkap');
}

async function sendToWhatsApp(date, income, expense, balance, userName, tranCount, poCount, businessName) {
    try {
        showLoadingModal('Membuka WhatsApp...');

        const response = await fetch(BASE_URL + '/api/send-whatsapp-report.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                total_income: income,
                total_expense: expense,
                net_balance: balance,
                user_name: userName,
                transaction_count: tranCount,
                po_count: poCount,
                business_name: businessName,
                admin_phone: document.getElementById('adminPhone')?.value || '+62'
            })
        });

        const result = await response.json();
        closeLoadingModal();

        if (result.status === 'success') {
            // Open WhatsApp
            if (result.whatsapp_url) {
                window.open(result.whatsapp_url, 'whatsapp');
                alert('✓ Laporan siap dikirim ke WhatsApp\n\nAnda dapat mengedit pesan sebelum mengirim');
            }
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        closeLoadingModal();
        alert('Error: ' + error.message);
    }
}

function confirmLogout() {
    if (confirm('Anda yakin ingin logout sekarang? Laporan sudah tersimpan.')) {
        closeEndShiftModal();
        window.location.href = BASE_URL + '/logout.php';
    }
}
