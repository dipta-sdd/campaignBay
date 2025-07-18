import { HashRouter, Route, Routes } from 'react-router-dom';
import AppLayout from './components/AppLayout';
import Dashboard from './pages/Dashboard';
import Settings from './pages/Settings';
import Campaigns from './pages/Campaigns';
import CampaignsAdd from './pages/CampaignsAdd';
import CampaignsEdit from './pages/CampaignsEdit';
import { ToastProvider } from './store/toast/toast-provider';
import { ToastContainer } from './components/ToastContainer';

const App = () => {

    return (
        <ToastProvider>
            <ToastContainer />
            <HashRouter>
                <Routes>
                    <Route path="/" element={<AppLayout />}>
                        <Route index element={<Dashboard />} />
                        <Route path="settings" element={<Settings />} />
                        <Route path="campaigns" element={<Campaigns />} />
                        <Route path="campaigns/add" element={<CampaignsAdd />} />
                        <Route path="campaigns/edit/:id" element={<CampaignsEdit />} />

                        {/* <Route path="rules/new" element={<AddNewRulePage />} /> */}
                        {/* <Route path="rules/edit/:ruleId" element={<AddNewRulePage />} />  */}
                    </Route>
                </Routes>
            </HashRouter>
        </ToastProvider>
    );
};

export default App;
