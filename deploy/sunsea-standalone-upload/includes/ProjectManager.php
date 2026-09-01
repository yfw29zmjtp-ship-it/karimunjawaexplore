<?php
/**
 * Project Management Class
 * Handles project and expense management with automatic investor balance deduction
 */

defined('APP_ACCESS') or die('Direct access not permitted');

class ProjectManager {
    private $db;
    private $projects_table = 'projects';
    private $expenses_table = 'project_expenses';
    private $categories_table = 'expense_categories';
    private $balance_table = 'project_balances';

    public function __construct($database) {
        $this->db = $database;
    }

    /**
     * Create new project
     */
    public function createProject($data, $created_by) {
        try {
            $query = "INSERT INTO {$this->projects_table} 
                      (name, description, budget, status, start_date, end_date)
                      VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $data['project_name'],
                $data['description'] ?? null,
                $data['budget_idr'] ?? null,
                $data['status'] ?? 'planning',
                $data['start_date'] ?? null,
                $data['end_date'] ?? null
            ]);

            $project_id = $this->db->lastInsertId();

            // Create project balance record
            $this->createProjectBalance($project_id);

            return [
                'success' => true,
                'message' => 'Project berhasil dibuat',
                'project_id' => $project_id
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all projects
     */
    public function getAllProjects($status = null) {
        $query = "SELECT p.*, 
                         pb.total_expenses_idr
                  FROM {$this->projects_table} p
                  LEFT JOIN {$this->balance_table} pb ON p.id = pb.project_id";
        
        if ($status) {
            $query .= " WHERE p.status = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->prepare($query);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }

    /**
     * Get project by ID
     */
    public function getProjectById($id) {
        $query = "SELECT p.*, pb.total_expenses_idr
                  FROM {$this->projects_table} p
                  LEFT JOIN {$this->balance_table} pb ON p.id = pb.project_id
                  WHERE p.id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Update project
     */
    public function updateProject($id, $data) {
        try {
            $query = "UPDATE {$this->projects_table} SET 
                      project_name = ?,
                      description = ?,
                      location = ?,
                      budget_idr = ?,
                      status = ?,
                      start_date = ?,
                      end_date = ?
                      WHERE id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $data['project_name'],
                $data['description'] ?? null,
                $data['location'] ?? null,
                $data['budget_idr'] ?? null,
                $data['status'] ?? 'planning',
                $data['start_date'] ?? null,
                $data['end_date'] ?? null,
                $id
            ]);

            return [
                'success' => true,
                'message' => 'Project berhasil diperbarui'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Add project expense
     * IMPORTANT: Automatically deducts investor balance when expense is approved
     */
    public function addExpense($project_id, $data, $created_by) {
        try {
            // Validate project exists
            $project = $this->getProjectById($project_id);
            if (!$project) {
                throw new Exception('Project tidak ditemukan');
            }

            // Get category name from category_id
            $category_name = 'Lain-lain';
            if (!empty($data['expense_category_id'])) {
                $cat_query = "SELECT name FROM expense_categories WHERE id = ?";
                $cat_stmt = $this->db->prepare($cat_query);
                $cat_stmt->execute([$data['expense_category_id']]);
                $cat_result = $cat_stmt->fetch();
                if ($cat_result) {
                    $category_name = $cat_result['name'];
                }
            }

            // Insert expense - match actual table structure
            $query = "INSERT INTO {$this->expenses_table}
                      (project_id, category, description, amount, expense_date, receipt_number)
                      VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                $project_id,
                $category_name,
                $data['description'] ?? null,
                $data['amount_idr'],
                $data['expense_date'],
                $data['reference_no'] ?? null
            ]);

            $expense_id = $this->db->lastInsertId();

            // Update project total_expenses
            $this->updateProjectExpenses($project_id);

            return [
                'success' => true,
                'message' => 'Pengeluaran berhasil ditambahkan',
                'expense_id' => $expense_id
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Approve expense - THIS IS THE KEY FUNCTION THAT DEDUCTS INVESTOR BALANCE
     */
    public function approveExpense($expense_id, $approved_by) {
        try {
            // Get expense details
            $query = "SELECT * FROM {$this->expenses_table} WHERE id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$expense_id]);
            $expense = $stmt->fetch();

            if (!$expense) {
                throw new Exception('Pengeluaran tidak ditemukan');
            }

            // Update expense status to approved
            $update_query = "UPDATE {$this->expenses_table} SET
                            status = 'approved',
                            approved_by = ?,
                            approved_at = NOW()
                            WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $stmt->execute([$approved_by, $expense_id]);

            // Update project balance
            $this->updateProjectBalance($expense['project_id']);

            // ========== CRITICAL: Update all investor balances ==========
            // When an expense is approved, it affects the shared investor pool
            $this->updateAllInvestorBalances();

            return [
                'success' => true,
                'message' => 'Pengeluaran disetujui dan saldo investor otomatis dikurangi'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reject expense
     */
    public function rejectExpense($expense_id, $rejected_by) {
        try {
            $query = "UPDATE {$this->expenses_table} SET
                      status = 'rejected',
                      approved_by = ?
                      WHERE id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$rejected_by, $expense_id]);

            // Get project and update balance
            $expense_query = "SELECT project_id FROM {$this->expenses_table} WHERE id = ?";
            $stmt = $this->db->prepare($expense_query);
            $stmt->execute([$expense_id]);
            $expense = $stmt->fetch();

            if ($expense) {
                $this->updateProjectBalance($expense['project_id']);
            }

            return [
                'success' => true,
                'message' => 'Pengeluaran ditolak'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update project total expenses
     */
    private function updateProjectExpenses($project_id) {
        try {
            // Calculate total expenses
            $query = "SELECT COALESCE(SUM(amount), 0) as total FROM {$this->expenses_table}
                     WHERE project_id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$project_id]);
            $result = $stmt->fetch();
            $total_expenses = $result['total'] ?? 0;

            // Update project record
            $update_query = "UPDATE {$this->projects_table} 
                            SET total_expenses = ?
                            WHERE id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $stmt->execute([$total_expenses, $project_id]);

            return true;
        } catch (Exception $e) {
            error_log('Error updating project expenses: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get expenses for project
     */
    public function getProjectExpenses($project_id, $status = null) {
        $query = "SELECT pe.*, pec.category_name
                  FROM {$this->expenses_table} pe
                  LEFT JOIN {$this->categories_table} pec ON pe.expense_category_id = pec.id
                  WHERE pe.project_id = ?";
        
        if ($status) {
            $query .= " AND pe.status = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$project_id, $status]);
        } else {
            $query .= " ORDER BY pe.expense_date DESC, pe.expense_time DESC";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$project_id]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Get expense categories
     */
    public function getExpenseCategories() {
        $query = "SELECT * FROM {$this->categories_table}
                  ORDER BY name";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Create project balance record
     */
    private function createProjectBalance($project_id) {
        $query = "INSERT INTO {$this->balance_table} (project_id, total_expenses_idr)
                  VALUES (?, 0)";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$project_id]);
    }

    /**
     * Update project balance (sum of all approved expenses)
     */
    public function updateProjectBalance($project_id) {
        try {
            $query = "SELECT COALESCE(SUM(amount_idr), 0) as total
                      FROM {$this->expenses_table}
                      WHERE project_id = ? AND status IN ('approved', 'paid')";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$project_id]);
            $result = $stmt->fetch();
            $total_expenses = $result['total'] ?? 0;

            // Update balance
            $update_query = "UPDATE {$this->balance_table} SET
                            total_expenses_idr = ?,
                            last_updated = NOW()
                            WHERE project_id = ?";
            
            $stmt = $this->db->prepare($update_query);
            $stmt->execute([$total_expenses, $project_id]);

            return true;
        } catch (Exception $e) {
            error_log('Error updating project balance: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update ALL investor balances when expenses change
     * This ensures investor saldo is correctly reduced when project expenses are approved
     */
    private function updateAllInvestorBalances() {
        try {
            // Get all investors
            $query = "SELECT DISTINCT id FROM investors WHERE status = 'active'";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $investors = $stmt->fetchAll();

            // For each investor, recalculate balance
            foreach ($investors as $investor) {
                // Calculate total capital
                $capital_query = "SELECT COALESCE(SUM(amount_idr), 0) as total 
                                 FROM investor_capital_transactions
                                 WHERE investor_id = ? AND status = 'confirmed'";
                
                $stmt = $this->db->prepare($capital_query);
                $stmt->execute([$investor['id']]);
                $capital_result = $stmt->fetch();
                $total_capital = $capital_result['total'] ?? 0;

                // Calculate total expenses (ALL projects - shared pool)
                $expenses_query = "SELECT COALESCE(SUM(pe.amount_idr), 0) as total
                                  FROM project_expenses pe
                                  WHERE pe.status IN ('approved', 'paid')";
                
                $stmt = $this->db->prepare($expenses_query);
                $stmt->execute();
                $expenses_result = $stmt->fetch();
                $total_expenses = $expenses_result['total'] ?? 0;

                // Calculate remaining balance
                $remaining_balance = $total_capital - $total_expenses;

                // Update investor balance
                $update_query = "UPDATE investor_balances SET
                                total_capital_idr = ?,
                                total_expenses_idr = ?,
                                remaining_balance_idr = ?,
                                last_updated = NOW()
                                WHERE investor_id = ?";
                
                $stmt = $this->db->prepare($update_query);
                $stmt->execute([
                    $total_capital,
                    $total_expenses,
                    $remaining_balance,
                    $investor['id']
                ]);
            }

            return true;
        } catch (Exception $e) {
            error_log('Error updating all investor balances: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get project expense summary by category (for charts)
     */
    public function getExpenseSummaryByCategory($project_id) {
        $query = "SELECT 
                    pec.category_name,
                    COALESCE(SUM(pe.amount_idr), 0) as total_amount,
                    COUNT(pe.id) as transaction_count
                  FROM {$this->categories_table} pec
                  LEFT JOIN {$this->expenses_table} pe ON pec.id = pe.expense_category_id AND pe.project_id = ? AND pe.status IN ('approved', 'paid')
                  WHERE pec.is_active = 1
                  GROUP BY pec.id, pec.category_name
                  ORDER BY total_amount DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$project_id]);
        return $stmt->fetchAll();
    }

    /**
     * Get all projects expense summary (for dashboard)
     */
    public function getAllProjectsExpenseSummary() {
        $query = "SELECT 
                    p.id,
                    p.project_name,
                    p.project_code,
                    COALESCE(SUM(pe.amount_idr), 0) as total_expenses
                  FROM {$this->projects_table} p
                  LEFT JOIN {$this->expenses_table} pe ON p.id = pe.project_id AND pe.status IN ('approved', 'paid')
                  WHERE p.status IN ('planning', 'ongoing')
                  GROUP BY p.id, p.project_name, p.project_code
                  ORDER BY total_expenses DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
?>
