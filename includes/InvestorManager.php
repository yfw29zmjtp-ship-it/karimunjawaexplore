<?php
/**
 * Investor Management Class
 * Handles all investor-related operations
 */

defined('APP_ACCESS') or die('Direct access not permitted');

class InvestorManager {
    private $db;
    private $table = 'investors';
    private $capital_table = 'investor_transactions';
    private $balance_table = 'investor_balances';

    public function __construct($database) {
        $this->db = $database;
    }

    /**
     * Create new investor
     */
    public function createInvestor($data, $created_by) {
        try {
            $query = "INSERT INTO {$this->table} (name, contact, email, notes)
                      VALUES (?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $data['name'],
                $data['contact'] ?? null,
                $data['email'] ?? null,
                $data['notes'] ?? null
            ]);

            $investor_id = $this->db->lastInsertId();

            // Create investor balance record
            $this->createInvestorBalance($investor_id);

            return [
                'success' => true,
                'message' => 'Investor berhasil ditambahkan',
                'investor_id' => $investor_id
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all investors
     */
    public function getAllInvestors($status = null) {
        $query = "SELECT i.*, 
                         ib.total_capital_idr, 
                         ib.total_expenses_idr,
                         ib.remaining_balance_idr
                  FROM {$this->table} i
                  LEFT JOIN {$this->balance_table} ib ON i.id = ib.investor_id";
        
        if ($status) {
            $query .= " WHERE i.status = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }

    /**
     * Get investor by ID with balance
     */
    public function getInvestorById($id) {
        $query = "SELECT i.*, 
                         ib.total_capital_idr, 
                         ib.total_expenses_idr,
                         ib.remaining_balance_idr
                  FROM {$this->table} i
                  LEFT JOIN {$this->balance_table} ib ON i.id = ib.investor_id
                  WHERE i.id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Update investor details
     */
    public function updateInvestor($id, $data) {
        try {
            $query = "UPDATE {$this->table} SET 
                      investor_name = ?,
                      investor_address = ?,
                      contact_phone = ?,
                      email = ?,
                      status = ?,
                      notes = ?
                      WHERE id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $data['investor_name'],
                $data['investor_address'],
                $data['contact_phone'] ?? null,
                $data['email'] ?? null,
                $data['status'] ?? 'active',
                $data['notes'] ?? null,
                $id
            ]);

            return [
                'success' => true,
                'message' => 'Investor berhasil diperbarui'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Add capital transaction (USD to IDR conversion)
     */
    public function addCapitalTransaction($investor_id, $data, $created_by) {
        try {
            // Get current exchange rate (fixed)
            $exchange_rate = 15500; // Fixed rate
            
            // Convert USD to IDR
            $amount_usd = $data['amount_usd'];
            $amount_idr = $amount_usd * $exchange_rate;

            // Insert transaction - simple version matching table structure
            $query = "INSERT INTO {$this->capital_table} 
                      (investor_id, type, amount, description, transaction_date)
                      VALUES (?, 'capital', ?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $investor_id,
                $amount_idr,
                $data['description'] ?? 'Modal setoran USD $' . number_format($amount_usd, 2) . ' @ Rp ' . number_format($exchange_rate, 0),
                $data['transaction_date']
            ]);

            // Update investor balance
            $this->updateInvestorBalance($investor_id);

            return [
                'success' => true,
                'message' => 'Transaksi modal berhasil ditambahkan',
                'amount_idr' => $amount_idr,
                'exchange_rate' => $exchange_rate
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get capital transactions for investor
     */
    public function getCapitalTransactions($investor_id) {
        $query = "SELECT * FROM {$this->capital_table}
                  WHERE investor_id = ? AND status = 'confirmed'
                  ORDER BY transaction_date DESC, transaction_time DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$investor_id]);
        return $stmt->fetchAll();
    }

    /**
     * Create investor balance record
     */
    private function createInvestorBalance($investor_id) {
        $query = "INSERT INTO {$this->balance_table} (investor_id, total_capital_idr, total_expenses_idr, remaining_balance_idr)
                  VALUES (?, 0, 0, 0)";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$investor_id]);
    }

    /**
     * Update investor balance (recalculate totals)
     */
    public function updateInvestorBalance($investor_id) {
        try {
            // Calculate total capital from capital transactions
            $query_capital = "SELECT COALESCE(SUM(amount), 0) as total FROM {$this->capital_table}
                             WHERE investor_id = ? AND type = 'capital'";
            
            $stmt = $this->db->prepare($query_capital);
            $stmt->execute([$investor_id]);
            $capital_result = $stmt->fetch();
            $total_capital_idr = $capital_result['total'] ?? 0;

            // Calculate total expenses
            $query_expenses = "SELECT COALESCE(SUM(amount), 0) as total FROM {$this->capital_table}
                              WHERE investor_id = ? AND type = 'expense'";
            
            $stmt = $this->db->prepare($query_expenses);
            $stmt->execute([$investor_id]);
            $expenses_result = $stmt->fetch();
            $total_expenses_idr = $expenses_result['total'] ?? 0;

            // Calculate remaining balance
            $remaining_balance_idr = $total_capital_idr - $total_expenses_idr;

            // Update investor main record
            $update_query = "UPDATE {$this->table} SET
                            total_capital = ?,
                            total_expenses = ?,
                            remaining_balance = ?
                            WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $stmt->execute([
                $total_capital_idr,
                $total_expenses_idr,
                $remaining_balance_idr,
                $investor_id
            ]);

            // Also update balance table if exists
            $balance_check = "SELECT id FROM {$this->balance_table} WHERE investor_id = ?";
            $stmt = $this->db->prepare($balance_check);
            $stmt->execute([$investor_id]);
            
            if ($stmt->fetch()) {
                $update_balance = "UPDATE {$this->balance_table} SET
                                  total_capital_idr = ?,
                                  total_expenses_idr = ?,
                                  remaining_balance_idr = ?,
                                  last_updated = NOW()
                                  WHERE investor_id = ?";
                $stmt = $this->db->prepare($update_balance);
                $stmt->execute([
                    $total_capital_idr,
                    $total_expenses_idr,
                    $remaining_balance_idr,
                    $investor_id
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log('Error updating investor balance: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current exchange rate (USD to IDR)
     */
    public function getCurrentExchangeRate() {
        $query = "SELECT usd_to_idr FROM exchange_rates
                  WHERE is_current = 1
                  ORDER BY date_of_rate DESC, time_of_rate DESC
                  LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result['usd_to_idr'] ?? null;
    }

    /**
     * Get investor capital summary for chart
     */
    public function getCapitalSummary() {
        $query = "SELECT 
                    i.id,
                    i.investor_name,
                    COALESCE(ib.total_capital_idr, 0) as total_capital
                  FROM {$this->table} i
                  LEFT JOIN {$this->balance_table} ib ON i.id = ib.investor_id
                  WHERE i.status = 'active'
                  ORDER BY total_capital DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Deduct investor balance when project expense is created
     * Called when a project expense is approved
     */
    public function deductBalance($amount_idr) {
        // This is called from ProjectExpenseManager
        // Balance is automatically updated when expenses are approved
        return true;
    }

    /**
     * Get investor balance
     */
    public function getBalance($investor_id) {
        $query = "SELECT * FROM {$this->balance_table} WHERE investor_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$investor_id]);
        return $stmt->fetch();
    }
}
?>
