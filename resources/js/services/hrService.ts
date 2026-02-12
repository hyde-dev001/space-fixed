// HR Module API Service

import axios from 'axios';
import { 
  Employee, 
  AttendanceRecord, 
  Payroll, 
  LeaveRequest, 
  PerformanceReview,
  Department,
  Position
} from '../types/hr';

const API_BASE_URL = '/api/erp/hr';

const axiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Add auth token to requests
axiosInstance.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Employee Services
export const employeeService = {
  getAll: async (params?: any) => {
    const response = await axiosInstance.get<Employee[]>('/employees', { params });
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<Employee>(`/employees/${id}`);
    return response.data;
  },

  create: async (data: Partial<Employee>) => {
    const response = await axiosInstance.post<Employee>('/employees', data);
    return response.data;
  },

  update: async (id: string, data: Partial<Employee>) => {
    const response = await axiosInstance.put<Employee>(`/employees/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/employees/${id}`);
  },

  search: async (query: string) => {
    const response = await axiosInstance.get<Employee[]>('/employees', { 
      params: { search: query } 
    });
    return response.data;
  },

  getByDepartment: async (departmentId: string) => {
    const response = await axiosInstance.get<Employee[]>('/employees', { 
      params: { department_id: departmentId } 
    });
    return response.data;
  },

  getStats: async () => {
    const response = await axiosInstance.get('/employees/stats');
    return response.data;
  },
};

// Attendance Services
export const attendanceService = {
  getAll: async (params?: any) => {
    const response = await axiosInstance.get<AttendanceRecord[]>('/attendance', { params });
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<AttendanceRecord>(`/attendance/${id}`);
    return response.data;
  },

  create: async (data: Partial<AttendanceRecord>) => {
    const response = await axiosInstance.post<AttendanceRecord>('/attendance', data);
    return response.data;
  },

  update: async (id: string, data: Partial<AttendanceRecord>) => {
    const response = await axiosInstance.put<AttendanceRecord>(`/attendance/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/attendance/${id}`);
  },

  getByEmployee: async (employeeId: string, params?: any) => {
    const response = await axiosInstance.get<AttendanceRecord[]>('/attendance', {
      params: { employee_id: employeeId, ...params }
    });
    return response.data;
  },

  checkIn: async (employeeId: string, biometricId?: string) => {
    const response = await axiosInstance.post('/attendance/check-in', {
      employee_id: employeeId,
      biometric_id: biometricId,
    });
    return response.data;
  },

  checkOut: async (employeeId: string) => {
    const response = await axiosInstance.post('/attendance/check-out', {
      employee_id: employeeId,
    });
    return response.data;
  },

  getStats: async (employeeId: string, period?: string) => {
    const response = await axiosInstance.get('/attendance/stats', {
      params: { employee_id: employeeId, period }
    });
    return response.data;
  },
};

// Payroll Services
export const payrollService = {
  getAll: async (params?: any) => {
    const response = await axiosInstance.get<Payroll[]>('/payroll', { params });
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<Payroll>(`/payroll/${id}`);
    return response.data;
  },

  create: async (data: Partial<Payroll>) => {
    const response = await axiosInstance.post<Payroll>('/payroll', data);
    return response.data;
  },

  update: async (id: string, data: Partial<Payroll>) => {
    const response = await axiosInstance.put<Payroll>(`/payroll/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/payroll/${id}`);
  },

  getByEmployee: async (employeeId: string, params?: any) => {
    const response = await axiosInstance.get<Payroll[]>('/payroll', {
      params: { employee_id: employeeId, ...params }
    });
    return response.data;
  },

  generatePayroll: async (period: string) => {
    const response = await axiosInstance.post('/payroll/generate', { period });
    return response.data;
  },

  processPayroll: async (payrollIds: string[]) => {
    const response = await axiosInstance.post('/payroll/process', { payroll_ids: payrollIds });
    return response.data;
  },

  exportPayslip: async (payrollId: string) => {
    const response = await axiosInstance.get(`/payroll/${payrollId}/payslip`, {
      responseType: 'blob'
    });
    return response.data;
  },

  getReport: async (params?: any) => {
    const response = await axiosInstance.get('/payroll/report', { params });
    return response.data;
  },
};

// Leave Request Services
export const leaveService = {
  getAll: async (params?: any) => {
    const response = await axiosInstance.get<LeaveRequest[]>('/leave-requests', { params });
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<LeaveRequest>(`/leave-requests/${id}`);
    return response.data;
  },

  create: async (data: Partial<LeaveRequest>) => {
    const response = await axiosInstance.post<LeaveRequest>('/leave-requests', data);
    return response.data;
  },

  update: async (id: string, data: Partial<LeaveRequest>) => {
    const response = await axiosInstance.put<LeaveRequest>(`/leave-requests/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/leave-requests/${id}`);
  },

  getByEmployee: async (employeeId: string) => {
    const response = await axiosInstance.get<LeaveRequest[]>('/leave-requests', {
      params: { employee_id: employeeId }
    });
    return response.data;
  },

  getPending: async () => {
    const response = await axiosInstance.get<LeaveRequest[]>('/leave-requests', {
      params: { status: 'pending' }
    });
    return response.data;
  },

  approve: async (id: string, approverId: string) => {
    const response = await axiosInstance.post(`/leave-requests/${id}/approve`, {
      approved_by: approverId
    });
    return response.data;
  },

  reject: async (id: string, reason: string) => {
    const response = await axiosInstance.post(`/leave-requests/${id}/reject`, {
      rejection_reason: reason
    });
    return response.data;
  },

  getBalance: async (employeeId: string) => {
    const response = await axiosInstance.get('/leave-requests/balance', {
      params: { employee_id: employeeId }
    });
    return response.data;
  },
};

// Performance Review Services
export const performanceService = {
  getAll: async (params?: any) => {
    const response = await axiosInstance.get<PerformanceReview[]>('/performance-reviews', { params });
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<PerformanceReview>(`/performance-reviews/${id}`);
    return response.data;
  },

  create: async (data: Partial<PerformanceReview>) => {
    const response = await axiosInstance.post<PerformanceReview>('/performance-reviews', data);
    return response.data;
  },

  update: async (id: string, data: Partial<PerformanceReview>) => {
    const response = await axiosInstance.put<PerformanceReview>(`/performance-reviews/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/performance-reviews/${id}`);
  },

  getByEmployee: async (employeeId: string) => {
    const response = await axiosInstance.get<PerformanceReview[]>('/performance-reviews', {
      params: { employee_id: employeeId }
    });
    return response.data;
  },

  submit: async (id: string) => {
    const response = await axiosInstance.post(`/performance-reviews/${id}/submit`);
    return response.data;
  },

  getReport: async (params?: any) => {
    const response = await axiosInstance.get('/performance-reviews/report', { params });
    return response.data;
  },
};

// Department Services
export const departmentService = {
  getAll: async () => {
    const response = await axiosInstance.get<Department[]>('/departments');
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<Department>(`/departments/${id}`);
    return response.data;
  },

  create: async (data: Partial<Department>) => {
    const response = await axiosInstance.post<Department>('/departments', data);
    return response.data;
  },

  update: async (id: string, data: Partial<Department>) => {
    const response = await axiosInstance.put<Department>(`/departments/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/departments/${id}`);
  },
};

// Position Services
export const positionService = {
  getAll: async () => {
    const response = await axiosInstance.get<Position[]>('/positions');
    return response.data;
  },

  getById: async (id: string) => {
    const response = await axiosInstance.get<Position>(`/positions/${id}`);
    return response.data;
  },

  create: async (data: Partial<Position>) => {
    const response = await axiosInstance.post<Position>('/positions', data);
    return response.data;
  },

  update: async (id: string, data: Partial<Position>) => {
    const response = await axiosInstance.put<Position>(`/positions/${id}`, data);
    return response.data;
  },

  delete: async (id: string) => {
    await axiosInstance.delete(`/positions/${id}`);
  },
};
