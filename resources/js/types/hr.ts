// HR Module Types and Interfaces

export interface Employee {
  id: string;
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  position: string;
  department: string;
  hireDate: string;
  status: 'active' | 'inactive' | 'on-leave' | 'suspended';
  salary: number;
  profileImage?: string;
  address: string;
  city: string;
  state: string;
  zipCode: string;
  emergencyContact: string;
  emergencyPhone: string;
  createdAt: string;
  updatedAt: string;
}

export interface AttendanceRecord {
  id: string;
  employeeId: string;
  date: string;
  checkInTime: string;
  checkOutTime: string;
  status: 'present' | 'absent' | 'late' | 'half-day';
  biometricId?: string;
  notes?: string;
  createdAt: string;
  updatedAt: string;
}

export interface Payroll {
  id: string;
  employeeId: string;
  payrollPeriod: string;
  baseSalary: number;
  allowances: number;
  deductions: number;
  netSalary: number;
  status: 'pending' | 'processed' | 'paid';
  paymentDate?: string;
  paymentMethod: 'bank-transfer' | 'check' | 'cash';
  notes?: string;
  createdAt: string;
  updatedAt: string;
}

export interface LeaveRequest {
  id: string;
  employeeId: string;
  leaveType: 'vacation' | 'sick' | 'personal' | 'maternity' | 'paternity' | 'unpaid';
  startDate: string;
  endDate: string;
  noOfDays: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  approvedBy?: string;
  approvalDate?: string;
  rejectionReason?: string;
  createdAt: string;
  updatedAt: string;
}

export interface PerformanceReview {
  id: string;
  employeeId: string;
  reviewerName: string;
  reviewDate: string;
  reviewPeriod: string;
  rating: number; // 1-5
  communication: number;
  teamwork: number;
  reliability: number;
  productivity: number;
  comments: string;
  goals?: string;
  improvementAreas?: string;
  status: 'draft' | 'submitted' | 'completed';
  createdAt: string;
  updatedAt: string;
}

export interface Department {
  id: string;
  name: string;
  manager: string;
  description?: string;
  employeeCount: number;
  createdAt: string;
  updatedAt: string;
}

export interface Position {
  id: string;
  title: string;
  department: string;
  description?: string;
  salary_range_min: number;
  salary_range_max: number;
  createdAt: string;
  updatedAt: string;
}

export interface LeaveBalance {
  employeeId: string;
  vacation: number;
  sick: number;
  personal: number;
  maternity?: number;
  paternity?: number;
  remaining: number;
  year: number;
}

export interface AttendanceStats {
  totalDays: number;
  presentDays: number;
  absentDays: number;
  lateDays: number;
  halfDays: number;
  attendanceRate: number;
  period: string;
}
